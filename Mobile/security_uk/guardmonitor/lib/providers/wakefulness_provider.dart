import 'dart:async';
import 'dart:convert';
import 'package:drift/drift.dart' show Value;
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'app_providers.dart';
import '../data/offline_queue_db.dart';
import '../services/notification_service.dart';
import '../services/secure_storage_service.dart';
import '../services/totp_service.dart';
import '../services/wakefulness_service.dart';

/// A challenge delivered with fewer seconds left than this is never shown — no
/// guard can read a code and tap 4 digits in time, so it would only ever
/// false-alarm. Below the floor we stay idle and let the server's own
/// missed-check handling raise the alert.
const int kMinResponseSeconds = 8;

/// Wakefulness codes are always **exactly 4 digits** — online (server push) and
/// offline (local TOTP) alike. The pin entry is fixed to this; any incoming code
/// is normalised to 4 (see [_normalizeCode]) so a server that sends an unpadded
/// value like `472` is shown and matched as `0472`, never as an un-submittable
/// 3-digit code.
const int kWakefulnessDigits = 4;

/// How long we'll wait for the server's authoritative PASS/FAIL on a locally-
/// correct code before falling back to the optimistic result. A real rejection
/// (expired window / clock skew) comes back fast; this only trips on a stalled
/// network, where trusting the guard who answered in time is the fair call.
const Duration kVerifyGrace = Duration(seconds: 4);

// idle → challenge → verifying → success | failed.
// `verifying` is the brief window where a locally-correct code is being
// reconciled with the server's authoritative verdict.
enum WakefulnessStatus { idle, challenge, verifying, success, failed }

class WakefulnessState {
  const WakefulnessState({
    this.status = WakefulnessStatus.idle,
    this.checkId = '',
    this.code = '',
    this.entry = '',
    this.secondsRemaining = 60,
    this.responseSeconds = 60,
    this.windowReference,
    this.scheduledAt,
    this.isOffline = false,
    this.startedAt,
    this.deadline,
  });

  final WakefulnessStatus status;
  final String checkId;
  final String code;
  final String entry;
  final int secondsRemaining;
  final int responseSeconds;
  // Set for a locally-computed TOTP challenge — sent on the offline replay so
  // the server can validate a code generated minutes ago.
  final int? windowReference;
  // The schedule mark this challenge fired at (UTC) — sent as `scheduled_at` on
  // the offline flush so the server timeline is exact rather than derived.
  final DateTime? scheduledAt;
  final bool isOffline;
  final DateTime? startedAt;
  // Wall-clock UTC instant the response window closes. The countdown is derived
  // from this each tick (not decremented), so a backgrounded app whose timer
  // froze re-syncs to the true remaining time the moment it resumes.
  final DateTime? deadline;

  WakefulnessState copyWith({
    WakefulnessStatus? status,
    String? checkId,
    String? code,
    String? entry,
    int? secondsRemaining,
    int? responseSeconds,
    int? windowReference,
    DateTime? scheduledAt,
    bool? isOffline,
    DateTime? startedAt,
    DateTime? deadline,
  }) =>
      WakefulnessState(
        status: status ?? this.status,
        checkId: checkId ?? this.checkId,
        code: code ?? this.code,
        entry: entry ?? this.entry,
        secondsRemaining: secondsRemaining ?? this.secondsRemaining,
        responseSeconds: responseSeconds ?? this.responseSeconds,
        windowReference: windowReference ?? this.windowReference,
        scheduledAt: scheduledAt ?? this.scheduledAt,
        isOffline: isOffline ?? this.isOffline,
        startedAt: startedAt ?? this.startedAt,
        deadline: deadline ?? this.deadline,
      );
}

class WakefulnessNotifier extends Notifier<WakefulnessState> {
  // check_ids already raised this session. Guards against the online push and
  // the local TOTP scheduler both challenging the same check (H2), and against
  // a late-arriving push re-challenging a check the guard already answered.
  // Bounded so it can't grow without limit on a very long shift.
  final _handled = <String>{};

  @override
  WakefulnessState build() => const WakefulnessState();

  /// Marks [checkId] handled. Returns false if it was already raised this
  /// session, so the caller skips re-challenging. An empty id (the mock
  /// `/welfare/pending` path, which has no stable id) is never deduped.
  bool _claim(String checkId) {
    if (checkId.isEmpty) return true;
    if (!_handled.add(checkId)) return false;
    if (_handled.length > 200) _handled.remove(_handled.first);
    return true;
  }

  /// Forgets the handled-check history — called when a shift ends so a fresh
  /// shift (or a different guard on the device) starts clean.
  void clearHistory() => _handled.clear();

  /// Server-pushed (online) challenge: the server generated [code] and sent it
  /// (via the mock `/welfare/pending` poll, or FCM). [issuedAt] is the server's
  /// issue time when known (from the push) — the countdown is anchored to it so
  /// a push delivered/tapped late can't hand back a full fresh window the server
  /// already spent (H3).
  void trigger(
    String checkId,
    String code, {
    int responseSeconds = 60,
    DateTime? issuedAt,
  }) {
    // Validate the code shape BEFORE claiming, so a malformed push doesn't burn
    // a dedup slot and a later corrected push for the same check can still raise.
    final normalized = _normalizeCode(code);
    if (normalized == null) return; // not exactly 4 digits — never raise it (M2)
    if (!_claim(checkId)) return;
    final now = DateTime.now().toUtc();
    final remaining = issuedAt == null
        ? responseSeconds
        : (responseSeconds - now.difference(issuedAt.toUtc()).inSeconds)
            .clamp(0, responseSeconds);
    // Too little of the window left to answer (a late-delivered push) — don't
    // raise a challenge that's doomed to false-alarm; the server raises its own
    // missed-check alert. The floor can't exceed the window itself.
    final floor =
        responseSeconds < kMinResponseSeconds ? responseSeconds : kMinResponseSeconds;
    if (remaining < floor) return;
    state = WakefulnessState(
      status: WakefulnessStatus.challenge,
      checkId: checkId,
      code: normalized,
      responseSeconds: responseSeconds,
      secondsRemaining: remaining,
      isOffline: false,
      startedAt: DateTime.now(),
      deadline: now.add(Duration(seconds: remaining)),
    );
  }

  /// Locally-computed (TOTP) challenge fired from the shift-start schedule. The
  /// answer has no server check_id, so it's recorded via the offline materialise
  /// endpoint keyed on its absolute [windowReference] (immediately when online,
  /// or buffered for the reconnect flush) — never via `/respond`.
  void triggerLocal(
    String checkId,
    String code, {
    required int windowReference,
    DateTime? scheduledAt,
    int responseSeconds = 60,
  }) {
    final normalized = _normalizeCode(code);
    if (normalized == null) return; // not exactly 4 digits — never raise it (M2)
    if (!_claim(checkId)) return;
    state = WakefulnessState(
      status: WakefulnessStatus.challenge,
      checkId: checkId,
      code: normalized,
      responseSeconds: responseSeconds,
      secondsRemaining: responseSeconds,
      windowReference: windowReference,
      scheduledAt: scheduledAt?.toUtc(),
      isOffline: true,
      startedAt: DateTime.now(),
      deadline: DateTime.now().toUtc().add(Duration(seconds: responseSeconds)),
    );
  }

  /// Normalises an issued code to **exactly** [kWakefulnessDigits] digits, or
  /// returns null when it can't be. Strips non-digits, then:
  /// - a SHORT value is a real 4-digit TOTP whose leading zero was dropped in
  ///   transport (server/notification sends `472` for a true `0472`) → zero-pad
  ///   back to 4. This restore is legitimate and must stay.
  /// - EXACTLY 4 → used as-is.
  /// - LONGER than 4 (or empty) is malformed — a `digits:4` TOTP can never
  ///   exceed 4 — so return null. The caller must NOT raise a challenge for it
  ///   (the pin is fixed at 4, so it could never be matched → a false miss);
  ///   staying idle lets the server raise its own missed-check instead.
  static String? _normalizeCode(String code) {
    final digits = code.replaceAll(RegExp(r'\D'), '');
    if (digits.isEmpty || digits.length > kWakefulnessDigits) return null;
    return digits.padLeft(kWakefulnessDigits, '0');
  }

  void addDigit(String digit) {
    if (state.status != WakefulnessStatus.challenge) return;
    if (state.entry.length >= kWakefulnessDigits) return;
    state = state.copyWith(entry: state.entry + digit);
  }

  void deleteDigit() {
    if (state.entry.isEmpty) return;
    state = state.copyWith(
      entry: state.entry.substring(0, state.entry.length - 1),
    );
  }

  /// Recomputes the remaining seconds from the wall-clock [deadline] rather than
  /// decrementing — so a timer that froze while the app was backgrounded
  /// re-syncs to the true value. Falls back to a plain decrement only if no
  /// deadline was set (shouldn't happen for a real challenge).
  int _remaining(WakefulnessState s) {
    final dl = s.deadline;
    if (dl == null) return s.secondsRemaining - 1;
    final r = dl.difference(DateTime.now().toUtc()).inSeconds;
    return r < 0 ? 0 : r;
  }

  /// Drives the countdown. Safe to call both on the 1s timer and on app-resume
  /// (it's an idempotent recompute, not a decrement).
  void tick() {
    if (state.status != WakefulnessStatus.challenge) return;
    final remaining = _remaining(state);
    if (remaining <= 0) {
      state = state.copyWith(
          status: WakefulnessStatus.failed, secondsRemaining: 0);
      ref.read(shiftProvider.notifier).recordWelfareCheck(passed: false);
      unawaited(_report(state, localPass: false));
    } else {
      state = state.copyWith(secondsRemaining: remaining);
    }
  }

  /// Resolves a tapped code. A wrong code fails instantly (kept snappy). A
  /// locally-correct code is *not* trusted on its own — the server is the
  /// authority on whether it's still in time, so we move to `verifying` and
  /// resolve on its verdict, falling back to the optimistic pass only if the
  /// server is too slow to answer (see [kVerifyGrace]). This stops the guard
  /// being told they passed a check the server recorded as a miss.
  Future<void> submit() async {
    final s = state;
    if (s.status != WakefulnessStatus.challenge) return;

    if (s.entry != s.code) {
      state = s.copyWith(status: WakefulnessStatus.failed, entry: '');
      ref.read(shiftProvider.notifier).recordWelfareCheck(passed: false);
      unawaited(_report(s, localPass: false));
      return;
    }

    state = s.copyWith(status: WakefulnessStatus.verifying);
    final passed = await _report(s, localPass: true)
        .timeout(kVerifyGrace, onTimeout: () => true);
    // The overlay may have been torn down (reset) while we waited.
    if (state.status != WakefulnessStatus.verifying) return;
    if (passed) {
      state = state.copyWith(status: WakefulnessStatus.success);
      ref.read(shiftProvider.notifier).recordWelfareCheck(passed: true);
    } else {
      state = state.copyWith(status: WakefulnessStatus.failed, entry: '');
      ref.read(shiftProvider.notifier).recordWelfareCheck(passed: false);
    }
  }

  void reset() => state = const WakefulnessState();

  /// Reports the outcome and returns the server's authoritative verdict. On an
  /// unreachable server (after the service's bounded retries) it returns
  /// [localPass] so a connectivity blip can't fail a guard who answered — the
  /// service keeps the record best-effort. Never throws.
  Future<bool> _report(WakefulnessState s, {required bool localPass}) async {
    // Schedule-fired (TOTP) challenge — it has no server check_id, so it can
    // never go to /respond. Send it to the offline materialise endpoint keyed on
    // the absolute window: when online we record it right away; when offline (or
    // the POST fails) we buffer it for the reconnect flush. Validity is proven by
    // the window, so a late flush still lands on the right step.
    if (s.isOffline) {
      if (s.windowReference == null || s.entry.length != kWakefulnessDigits) {
        return localPass;
      }
      final shiftId = ref.read(shiftProvider).id;
      if (shiftId == null) return localPass;
      if (kDebugMode) {
        debugPrint('[wakefulness] answer via OFFLINE endpoint '
            '(/shifts/$shiftId/wakefulness/offline) window=${s.windowReference}');
      }
      try {
        await ref.read(wakefulnessServiceProvider).submitOffline(
              shiftId: shiftId,
              code: s.entry,
              windowReference: s.windowReference!,
              respondedAt: DateTime.now().toUtc().toIso8601String(),
              scheduledAt: s.scheduledAt?.toIso8601String(),
            );
      } catch (_) {
        await _enqueueOfflineAnswer(s);
      }
      // The UI verdict is the local code comparison (the server re-derives the
      // same TOTP and never disagrees on the digits); a closed offline window
      // raises no retroactive alert either way.
      return localPass;
    }

    // Online challenge with a real check_id — the server returns the
    // authoritative PASS/FAIL (catches clock-skew where the local code is right
    // but the server window already expired).
    if (s.checkId.isEmpty) return localPass;
    if (kDebugMode) {
      debugPrint('[wakefulness] answer via ONLINE endpoint '
          '(/wakefulness/${s.checkId}/respond)');
    }
    try {
      return await ref.read(wakefulnessServiceProvider).respond(s.checkId, s.entry);
    } catch (_) {
      // Server unreachable after the service's retries — trust the local result.
      return localPass;
    }
  }

  /// Buffers an unsent offline wakefulness answer for the reconnect flush.
  /// Best-effort — never throws.
  Future<void> _enqueueOfflineAnswer(WakefulnessState s) async {
    final shiftId = ref.read(shiftProvider).id;
    if (shiftId == null) return;
    try {
      await ref.read(offlineQueueDbProvider).enqueueWakefulness(
            WakefulnessQueueCompanion.insert(
              shiftId: shiftId,
              checkId: s.checkId,
              code: s.entry,
              windowReference: s.windowReference!,
              scheduledAt: Value(s.scheduledAt?.toIso8601String()),
              respondedAt: DateTime.now().toUtc().toIso8601String(),
              createdAt: DateTime.now().millisecondsSinceEpoch,
            ),
          );
    } catch (_) {
      // Queue unavailable — drop; wakefulness replay is best-effort.
    }
  }
}

final wakefulnessProvider =
    NotifierProvider<WakefulnessNotifier, WakefulnessState>(WakefulnessNotifier.new);

// ── Wakefulness provisioning (TOTP seed + schedule from POST /shifts/{id}/start) ─

class WakefulnessMark {
  const WakefulnessMark({required this.time, this.checkId});
  final DateTime time; // UTC
  final String? checkId;

  Map<String, dynamic> toJson() => {
        'time': time.toUtc().toIso8601String(),
        'check_id': checkId,
      };

  static WakefulnessMark? fromAny(dynamic raw) {
    // Accepts a bare ISO string, or an object {scheduled_at|time, check_id}.
    if (raw is String) {
      final t = DateTime.tryParse(raw);
      return t != null ? WakefulnessMark(time: t.toUtc()) : null;
    }
    if (raw is Map) {
      final t = DateTime.tryParse(
          (raw['scheduled_at'] ?? raw['time'] ?? raw['at'] ?? '').toString());
      if (t == null) return null;
      return WakefulnessMark(
        time: t.toUtc(),
        checkId: (raw['check_id'] ?? raw['id'])?.toString(),
      );
    }
    return null;
  }
}

class WakefulnessProvisioning {
  const WakefulnessProvisioning({
    required this.seed,
    this.period = 30,
    this.digits = 4,
    this.responseSeconds = 60,
    this.marks = const [],
  });

  final String seed;
  final int period;
  final int digits;
  final int responseSeconds;
  final List<WakefulnessMark> marks;

  Map<String, dynamic> toJson() => {
        'seed': seed,
        'period': period,
        'digits': digits,
        'response_seconds': responseSeconds,
        'marks': marks.map((m) => m.toJson()).toList(),
      };

  static WakefulnessProvisioning? fromJson(Map<String, dynamic> json) {
    final seed = (json['totp_seed'] ?? json['seed'])?.toString();
    if (seed == null || seed.isEmpty) return null;
    final rawSchedule = json['schedule'] ?? json['marks'] ?? const [];
    final marks = (rawSchedule is List)
        ? rawSchedule
            .map(WakefulnessMark.fromAny)
            .whereType<WakefulnessMark>()
            .toList()
        : <WakefulnessMark>[];
    return WakefulnessProvisioning(
      seed: seed,
      period: (json['totp_period_seconds'] ?? json['period'] ?? 30) as int,
      digits: (json['totp_digits'] ?? json['digits'] ?? 4) as int,
      responseSeconds: (json['response_seconds'] ?? 60) as int,
      marks: marks,
    );
  }
}

/// Holds the current shift's wakefulness provisioning and drives locally-timed
/// TOTP challenges. Checked on the home-screen poll cadence while a shift is
/// active; when a scheduled mark comes due it computes the code and triggers
/// the overlay. (Foreground only — precise background delivery needs push, H5.)
class WakefulnessScheduleNotifier extends Notifier<WakefulnessProvisioning?> {
  final Set<String> _fired = {};

  @override
  WakefulnessProvisioning? build() => null;

  String _key(WakefulnessMark m) => m.checkId ?? m.time.toIso8601String();

  /// Marks [key] fired in memory AND persists the whole set, so a mid-shift
  /// app-kill can't re-raise (and double-count) an already-handled welfare mark.
  /// Fire-and-forget — a storage failure must never break the schedule.
  void _markFired(String key) {
    _fired.add(key);
    unawaited(
      SecureStorageService.saveWakefulnessFired(_fired.toList())
          .catchError((Object _) {}),
    );
  }

  /// Parses + persists provisioning from the start response. No-op when the
  /// backend doesn't issue a seed (e.g. the local mock) — leaves the legacy
  /// `/welfare/pending` poll as the active path.
  Future<void> provisionFromJson(Map<String, dynamic>? json) async {
    if (json == null) return;
    final prov = WakefulnessProvisioning.fromJson(json);
    if (prov == null) return;
    _fired.clear();
    state = prov;
    await SecureStorageService.saveWakefulness(jsonEncode(prov.toJson()));
    // Fresh schedule → forget any fired marks carried from a previous shift.
    await SecureStorageService.clearWakefulnessFired();
    // Register OS-level local notifications at each mark so an OFFLINE /
    // backgrounded guard is still prompted (there's no push when offline).
    await NotificationService.scheduleWakefulnessChecks(
        prov.marks.map((m) => m.time).toList());
  }

  /// Re-arms from secure storage after an app relaunch mid-shift.
  Future<void> restore() async {
    if (state != null) return;
    final raw = await SecureStorageService.getWakefulness();
    if (raw == null) return;
    // Restore the fired-marks set FIRST so a relaunch doesn't re-challenge (and
    // re-count) a welfare mark already handled before the app was killed.
    _fired
      ..clear()
      ..addAll(await SecureStorageService.getWakefulnessFired());
    try {
      final prov = WakefulnessProvisioning.fromJson(
          jsonDecode(raw) as Map<String, dynamic>);
      if (prov != null) {
        state = prov;
        // Re-arm the local notifications too (the OS may have cleared scheduled
        // ones on reboot / app reinstall).
        await NotificationService.scheduleWakefulnessChecks(
            prov.marks.map((m) => m.time).toList());
      }
    } catch (_) {}
  }

  Future<void> clear() async {
    _fired.clear();
    state = null;
    await SecureStorageService.clearWakefulness();
    await SecureStorageService.clearWakefulnessFired();
    await NotificationService.cancelWakefulnessChecks();
  }

  bool get isArmed => state != null;

  /// Called on each active-shift poll. If a scheduled challenge is due now and
  /// still inside its response window, computes the TOTP code and triggers the
  /// overlay. Marks each mark fired once handled (or once its window lapses).
  void checkSchedule() {
    final prov = state;
    if (prov == null) return;
    if (ref.read(wakefulnessProvider).status != WakefulnessStatus.idle) return;

    final now = DateTime.now().toUtc();
    for (final mark in prov.marks) {
      final key = _key(mark);
      if (_fired.contains(key)) continue;

      final elapsed = now.difference(mark.time).inSeconds;
      if (elapsed < 0) continue; // not due yet

      // Window lapsed before we could show it (app was closed) — the server
      // raises its own CRITICAL; nothing useful to display now.
      if (elapsed > prov.responseSeconds) {
        _markFired(key);
        continue;
      }

      _markFired(key);
      final win = Totp.window(mark.time, period: prov.period);
      final code = Totp.codeForWindow(prov.seed, win, digits: prov.digits);
      ref.read(wakefulnessProvider.notifier).triggerLocal(
            mark.checkId ?? 'totp-$win',
            code,
            windowReference: win,
            scheduledAt: mark.time,
            responseSeconds: prov.responseSeconds,
          );
      return;
    }
  }
}

final wakefulnessScheduleProvider =
    NotifierProvider<WakefulnessScheduleNotifier, WakefulnessProvisioning?>(
        WakefulnessScheduleNotifier.new);
