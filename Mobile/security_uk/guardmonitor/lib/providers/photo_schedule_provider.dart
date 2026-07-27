import 'dart:async';
import 'dart:convert';

import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../services/notification_service.dart';
import '../services/secure_storage_service.dart';
import '../services/time_anchor_service.dart';
import 'photo_provider.dart';

/// The offline-photo schedule + window params provisioned by
/// `POST /shifts/{id}/start` (the `photos` block — Phase 7, backend Option A).
/// One schedule drives both paths: online a mark arrives as a pushed
/// `PHOTO_REQUEST`; offline the app self-fires the camera at the mark against a
/// pool nonce. See `PHASE_7_OFFLINE_PHOTO_TRIGGER_QUESTION.md`.
class PhotoProvisioning {
  const PhotoProvisioning({
    required this.schedule,
    this.responseSeconds = 90,
    this.offlineNonceTtlMinutes = 15,
    this.maxPhotosPerCapture = 5,
  });

  /// Randomised UTC due-times. No per-mark id — the server matches a fired
  /// capture to a mark by timestamp (±60 s).
  final List<DateTime> schedule;

  /// Online window only (a pushed request). Not used for offline captures —
  /// offline validity is purely the pool-nonce TTL.
  final int responseSeconds;

  /// Offline: informational metadata from the server reporting how long a pool
  /// nonce prefetched *now* stays valid. As of the 2026-07-23 backend fix this is
  /// **shift-spanning** (e.g. ~500 for an 8 h shift), not a fixed 15 — so one
  /// prefetch at start covers every 50–70-min mark. Nonce validity is enforced
  /// per-nonce via each nonce's own stored `expires_at` (see `NoncePoolService`),
  /// NOT off this aggregate — so this value is display/round-trip only and must
  /// never gate the fire decision (see [fireWindowMinutes] / [dueMark]).
  final int offlineNonceTtlMinutes;

  /// How late (minutes past a mark) an offline scheduled capture may still fire.
  /// Deliberately a **fixed, bounded** window — decoupled from
  /// [offlineNonceTtlMinutes], which now spans the whole shift and would
  /// otherwise let a capture fire hours late (e.g. after the app was killed for a
  /// long stretch), landing far outside the mark the server matches by timestamp.
  /// The 20 s active poll normally fires a due mark within one cycle; this only
  /// bounds the app-relaunched-late case.
  static const int fireWindowMinutes = 15;

  /// Up to this many images per capture (`photos[]`/`signatures[]`).
  final int maxPhotosPerCapture;

  Map<String, dynamic> toJson() => {
        'schedule': schedule.map((t) => t.toUtc().toIso8601String()).toList(),
        'response_seconds': responseSeconds,
        'offline_nonce_ttl_minutes': offlineNonceTtlMinutes,
        'max_photos_per_capture': maxPhotosPerCapture,
      };

  String keyFor(DateTime mark) => mark.toUtc().toIso8601String();

  /// The first schedule mark that is **due at [now]** (now ≥ mark) and **still
  /// inside the bounded fire window** (now ≤ mark + [fireWindowMinutes]), skipping
  /// anything already in [fired]. Returns null when nothing should fire. Pure +
  /// tamper-agnostic — the caller passes a trusted (NTP-anchored) [now]; a mark
  /// whose window has lapsed is silently skipped (the server records the missed
  /// scheduled check). Uses [fireWindowMinutes], NOT the nonce TTL: the nonce is
  /// now valid all shift, but a capture fired hours late wouldn't match this mark.
  DateTime? dueMark(DateTime now, Set<String> fired) {
    const windowSeconds = fireWindowMinutes * 60;
    for (final mark in schedule) {
      if (fired.contains(keyFor(mark))) continue;
      final elapsed = now.difference(mark).inSeconds;
      if (elapsed < 0) continue; // not due yet
      if (elapsed > windowSeconds) continue; // window lapsed
      return mark;
    }
    return null;
  }

  static PhotoProvisioning? fromJson(Map<String, dynamic>? json) {
    if (json == null) return null;
    final rawSchedule = json['schedule'];
    if (rawSchedule is! List) return null;
    final marks = rawSchedule
        .map((e) => DateTime.tryParse(e.toString()))
        .whereType<DateTime>()
        .map((t) => t.toUtc())
        .toList()
      // Sort ascending so `dueMark` fires marks in chronological order
      // regardless of the order the server listed them in (L3).
      ..sort();
    // A photos block with no parseable marks is treated as "not provisioned".
    if (marks.isEmpty) return null;
    return PhotoProvisioning(
      schedule: marks,
      responseSeconds: (json['response_seconds'] as num?)?.toInt() ?? 90,
      offlineNonceTtlMinutes:
          (json['offline_nonce_ttl_minutes'] as num?)?.toInt() ?? 15,
      maxPhotosPerCapture:
          (json['max_photos_per_capture'] as num?)?.toInt() ?? 5,
    );
  }
}

/// Drives **offline** photo captures from the provisioned schedule. Mirrors
/// `WakefulnessScheduleNotifier`: checked on the active-shift poll, fires the
/// camera when a mark is due **while offline**. Online marks are delivered by
/// the server as `PHOTO_REQUEST` (push or `/photos/pending` poll), so this never
/// self-fires while online — one schedule, no double-fire.
class PhotoScheduleNotifier extends Notifier<PhotoProvisioning?> {
  final Set<String> _fired = {};

  @override
  PhotoProvisioning? build() => null;

  bool get isArmed => state != null;

  /// Marks [key] fired in memory AND persists the whole set, so a mid-shift
  /// app-kill can't re-fire (out of nowhere) a scheduled photo the guard already
  /// handled. Fire-and-forget — a storage failure must never break the schedule.
  void _markFired(String key) {
    _fired.add(key);
    unawaited(
      SecureStorageService.savePhotoFired(_fired.toList())
          .catchError((Object _) {}),
    );
  }

  /// Parses + persists the `photos` block from the start response. No-op when the
  /// backend didn't issue one (older builds / the local mock).
  Future<void> provisionFromJson(Map<String, dynamic>? json) async {
    final prov = PhotoProvisioning.fromJson(json);
    if (prov == null) return;
    _fired.clear();
    state = prov;
    await SecureStorageService.savePhotoSchedule(jsonEncode(prov.toJson()));
    // Fresh schedule → forget any fired marks carried from a previous shift.
    await SecureStorageService.clearPhotoFired();
    // OS-level reminders at each mark so an OFFLINE / backgrounded guard is still
    // prompted to capture (no push reaches an offline device).
    await NotificationService.schedulePhotoChecks(prov.schedule);
  }

  /// Re-arms from secure storage after an app relaunch mid-shift.
  Future<void> restore() async {
    if (state != null) return;
    final raw = await SecureStorageService.getPhotoSchedule();
    if (raw == null) return;
    // Restore the fired-marks set FIRST so a relaunch doesn't re-fire a scheduled
    // photo already handled before the app was killed.
    _fired
      ..clear()
      ..addAll(await SecureStorageService.getPhotoFired());
    try {
      final prov =
          PhotoProvisioning.fromJson(jsonDecode(raw) as Map<String, dynamic>);
      if (prov != null) {
        state = prov;
        await NotificationService.schedulePhotoChecks(prov.schedule);
      }
    } catch (_) {}
  }

  Future<void> clear() async {
    _fired.clear();
    state = null;
    await SecureStorageService.clearPhotoSchedule();
    await SecureStorageService.clearPhotoFired();
    await NotificationService.cancelPhotoChecks();
  }

  /// Called on each active-shift poll. When [offline] and a scheduled mark is
  /// due (and still inside the pool-nonce window), fires the offline camera by
  /// flagging a scheduled capture for the home screen to open.
  ///
  /// **Tamper-resistant:** due-ness is judged against the NTP-anchored
  /// [TimeAnchorService.trustedNow], not `DateTime.now()` — changing the device
  /// clock can't dodge or force a scheduled photo.
  void checkSchedule({required bool offline}) {
    final prov = state;
    if (prov == null) return;
    // Online marks arrive as a server PHOTO_REQUEST — don't self-fire.
    if (!offline) return;
    // Don't stack a capture on top of one already in progress.
    if (ref.read(photoProvider).status != PhotoStatus.idle) return;
    if (ref.read(pendingPhotoProvider).pending) return;

    // NTP-anchored time, not the device clock — clock manipulation can't dodge
    // or force a scheduled photo.
    final now = ref.read(timeAnchorServiceProvider).trustedNow();
    final mark = prov.dueMark(now, _fired);
    if (mark == null) return;
    _markFired(prov.keyFor(mark));
    ref.read(pendingPhotoProvider.notifier).setScheduledOffline();
  }
}

final photoScheduleProvider =
    NotifierProvider<PhotoScheduleNotifier, PhotoProvisioning?>(
        PhotoScheduleNotifier.new);
