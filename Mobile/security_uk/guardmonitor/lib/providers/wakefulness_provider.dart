import 'dart:async';
import 'dart:convert';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'app_providers.dart';
import '../services/secure_storage_service.dart';
import '../services/totp_service.dart';
import '../services/wakefulness_service.dart';

/// A challenge delivered with fewer seconds left than this is never shown — no
/// guard can read a code and tap 4 digits in time, so it would only ever
/// false-alarm. Below the floor we stay idle and let the server's own
/// missed-check handling raise the alert.
const int kMinResponseSeconds = 8;

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
      code: code,
      responseSeconds: responseSeconds,
      secondsRemaining: remaining,
      isOffline: false,
      startedAt: DateTime.now(),
      deadline: now.add(Duration(seconds: remaining)),
    );
  }

  /// Locally-computed (TOTP) challenge fired from the shift-start schedule. The
  /// answer is replayed to the server with its [windowReference] + `is_offline`.
  void triggerLocal(
    String checkId,
    String code, {
    required int windowReference,
    int responseSeconds = 60,
  }) {
    if (!_claim(checkId)) return;
    state = WakefulnessState(
      status: WakefulnessStatus.challenge,
      checkId: checkId,
      code: code,
      responseSeconds: responseSeconds,
      secondsRemaining: responseSeconds,
      windowReference: windowReference,
      isOffline: true,
      startedAt: DateTime.now(),
      deadline: DateTime.now().toUtc().add(Duration(seconds: responseSeconds)),
    );
  }

  void addDigit(String digit) {
    if (state.status != WakefulnessStatus.challenge) return;
    if (state.entry.length >= 4) return;
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
    if (s.checkId.isEmpty) return localPass;
    try {
      return await ref.read(wakefulnessServiceProvider).respond(
            s.checkId,
            s.entry,
            windowReference: s.windowReference,
            isOffline: s.isOffline,
          );
    } catch (_) {
      return localPass;
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
  }

  /// Re-arms from secure storage after an app relaunch mid-shift.
  Future<void> restore() async {
    if (state != null) return;
    final raw = await SecureStorageService.getWakefulness();
    if (raw == null) return;
    try {
      final prov = WakefulnessProvisioning.fromJson(
          jsonDecode(raw) as Map<String, dynamic>);
      if (prov != null) state = prov;
    } catch (_) {}
  }

  Future<void> clear() async {
    _fired.clear();
    state = null;
    await SecureStorageService.clearWakefulness();
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
        _fired.add(key);
        continue;
      }

      _fired.add(key);
      final win = Totp.window(mark.time, period: prov.period);
      final code = Totp.codeForWindow(prov.seed, win, digits: prov.digits);
      ref.read(wakefulnessProvider.notifier).triggerLocal(
            mark.checkId ?? 'totp-$win',
            code,
            windowReference: win,
            responseSeconds: prov.responseSeconds,
          );
      return;
    }
  }
}

final wakefulnessScheduleProvider =
    NotifierProvider<WakefulnessScheduleNotifier, WakefulnessProvisioning?>(
        WakefulnessScheduleNotifier.new);
