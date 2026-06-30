import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/current_shift_model.dart';
import '../services/gps_service.dart';
import '../services/notification_service.dart';
import '../services/shift_service.dart';
import 'photo_schedule_provider.dart';
import 'ui_providers.dart';
import 'wakefulness_provider.dart';

// ── Current shift (server source of truth — id, window, can_start/can_end) ─

class CurrentShiftNotifier extends Notifier<CurrentShiftModel?> {
  @override
  CurrentShiftModel? build() => null;

  Future<void> fetch() async {
    try {
      final result = await ref.read(shiftServiceProvider).fetchCurrent();
      // Some backends stop listing a shift under GET /shifts/current once it
      // becomes active/checked_in. Don't let that null-out wipe an in-progress
      // shift — doing so would disable the END button and break auto-resume.
      if (result == null && ref.read(shiftProvider).active) return;
      state = _withPreservedPendingLock(result);
    } catch (_) {
      // Not critical — guard can still see the cached state; next poll/resume retries.
    }
  }

  /// Holds a locally-set `pending` early-end lock across polls until the server
  /// returns a *terminal* signal for it. Without this, any `GET /shifts/current`
  /// whose payload omits `early_end_request` would clear the lock via the
  /// wholesale `state = result`, silently unlocking the END button mid-wait (M1).
  /// A terminal signal — the server echoing a status (approved/rejected) or the
  /// shift itself completing/cancelling — is always honoured.
  CurrentShiftModel? _withPreservedPendingLock(CurrentShiftModel? incoming) {
    if (incoming == null) return incoming;
    final wasPending = state?.earlyEndPending ?? false;
    if (!wasPending) return incoming;
    // Server spoke about the request (approved/rejected) — honour its decision.
    if (incoming.earlyEndStatus != null) return incoming;
    // Shift reached a terminal state — let the real status through.
    if (incoming.status == 'completed' || incoming.status == 'cancelled') {
      return incoming;
    }
    // Backend just didn't echo the request — keep the local pending lock.
    return incoming.copyWith(
      earlyEndStatus: 'pending',
      earlyEndReason: state?.earlyEndReason,
      earlyEndNote: state?.earlyEndNote,
    );
  }

  /// Calls `POST /shifts/{id}/start`. Throws [DioException] on
  /// `409 SHIFT_NOT_STARTABLE` etc. so the caller can surface the error.
  /// The response is partial (id, status, actual_start, can_end only) so we
  /// merge the changed fields into the existing full state from GET /shifts/current.
  Future<CurrentShiftModel> start() async {
    final current = state;
    if (current == null) throw StateError('No current shift to start.');
    final result = await ref.read(shiftServiceProvider).startShift(current.id);
    // Provision the wakefulness TOTP schedule + the offline-photo schedule if
    // the backend returned them. Best-effort and non-blocking — a failure here
    // must not stop the shift.
    ref.read(wakefulnessScheduleProvider.notifier)
        .provisionFromJson(result.wakefulness);
    ref.read(photoScheduleProvider.notifier).provisionFromJson(result.photos);
    final updated = CurrentShiftModel(
      id: current.id,
      reference: current.reference,
      status: 'active',
      scheduledStart: current.scheduledStart,
      scheduledEnd: current.scheduledEnd,
      canStart: false,
      canEnd: true,
      canRequestEarlyEnd: false,
      actualStart: result.actualStart,
      site: current.site,
      geofence: current.geofence,
      role: current.role,
      notes: current.notes,
    );
    state = updated;
    return updated;
  }

  /// Calls `POST /shifts/{id}/end`. Throws [DioException] on
  /// `409 SHIFT_NOT_ENDABLE`, `END_BEFORE_SCHEDULED`, `EARLY_END_NOT_APPROVED`
  /// etc. so the caller can surface the error.
  /// Merges partial response into existing state, same as start().
  Future<CurrentShiftModel> end({
    bool endedEarly = false,
    String? reason,
    String? note,
  }) async {
    final current = state;
    if (current == null) throw StateError('No current shift to end.');
    final result = await ref.read(shiftServiceProvider).endShift(
          current.id,
          endedEarly: endedEarly,
          reason: reason,
          note: note,
        );
    final updated = CurrentShiftModel(
      id: current.id,
      reference: current.reference,
      status: 'completed',
      scheduledStart: current.scheduledStart,
      scheduledEnd: current.scheduledEnd,
      canStart: false,
      canEnd: false,
      canRequestEarlyEnd: false,
      actualStart: result.actualStart ?? current.actualStart,
      actualEnd: result.actualEnd,
      durationHours: result.durationHours,
      endType: result.endType,
      site: current.site,
      geofence: current.geofence,
      role: current.role,
      notes: current.notes,
    );
    state = updated;
    return updated;
  }

  /// Submits an early-end request for supervisor approval. Optimistically marks
  /// the local state `pending` so the UI locks immediately; the 20s
  /// `GET /shifts/current` poll then reconciles with the server's decision.
  /// Rethrows [DioException] so the caller can surface a failed submission.
  Future<void> requestEarlyEnd({
    required String reason,
    required String note,
  }) async {
    final current = state;
    if (current == null) {
      throw StateError('No current shift for an early-end request.');
    }
    await ref.read(shiftServiceProvider).requestEarlyEnd(
          current.id,
          reason: reason,
          note: note,
        );
    state = current.copyWith(
      earlyEndStatus: 'pending',
      earlyEndReason: reason,
      earlyEndNote: note,
    );
  }

  void clear() => state = null;
}

final currentShiftProvider =
    NotifierProvider<CurrentShiftNotifier, CurrentShiftModel?>(CurrentShiftNotifier.new);

// ── Shift (in-progress bookkeeping: elapsed time, welfare/photo counters) ──

class ShiftState {
  const ShiftState({
    this.active = false,
    this.startTime,
    this.id,
    this.shiftRef,
    this.welfareChecksTotal = 0,
    this.welfareChecksPassed = 0,
    this.photosTotal = 0,
    this.photosPassed = 0,
  });
  final bool active;
  final DateTime? startTime;
  final String? id;       // server-assigned shift ID
  final String? shiftRef; // display ref e.g. '#SH-2847'
  final int welfareChecksTotal;
  final int welfareChecksPassed;
  final int photosTotal;
  final int photosPassed;

  ShiftState copyWith({
    bool? active,
    DateTime? startTime,
    String? id,
    String? shiftRef,
    int? welfareChecksTotal,
    int? welfareChecksPassed,
    int? photosTotal,
    int? photosPassed,
  }) {
    return ShiftState(
      active: active ?? this.active,
      startTime: startTime ?? this.startTime,
      id: id ?? this.id,
      shiftRef: shiftRef ?? this.shiftRef,
      welfareChecksTotal: welfareChecksTotal ?? this.welfareChecksTotal,
      welfareChecksPassed: welfareChecksPassed ?? this.welfareChecksPassed,
      photosTotal: photosTotal ?? this.photosTotal,
      photosPassed: photosPassed ?? this.photosPassed,
    );
  }
}

class ShiftNotifier extends Notifier<ShiftState> {
  @override
  ShiftState build() => const ShiftState();

  /// The server decides whether a shift can start (`can_start`) — this calls
  /// straight through to the API. A successful POST (HTTP 2xx) ALWAYS flips the
  /// app into the active state, even if the response body couldn't be parsed:
  /// the shift has started on the server, so the UI must reflect that or the
  /// guard is stranded on a disabled START button. Only a [DioException]
  /// (a real HTTP rejection like `409 SHIFT_NOT_STARTABLE`) propagates so the
  /// caller can surface it.
  Future<void> start() async {
    CurrentShiftModel? updated;
    try {
      updated = await ref.read(currentShiftProvider.notifier).start();
    } on DioException {
      rethrow; // genuine server rejection — let the UI show the error
    } catch (_) {
      // 2xx received but post-response work threw (body parse, etc.).
      // Fall back to whatever the last GET /shifts/current gave us.
      updated = ref.read(currentShiftProvider);
    }

    final fallback = ref.read(currentShiftProvider);
    final shiftId = updated?.id ?? fallback?.id ?? state.id;
    state = ShiftState(
      active: true,
      startTime: updated?.actualStart ?? fallback?.actualStart ?? DateTime.now(),
      id: shiftId,
      shiftRef: updated?.displayRef ?? fallback?.displayRef ?? state.shiftRef,
    );
    if (shiftId != null) {
      ref.read(gpsServiceProvider).startCapture(shiftId).then(
            (tracking) =>
                ref.read(locationDeniedProvider.notifier).set(!tracking),
          );
    }

    // Schedule the "shift ended" reminder for the scheduled end time.
    final scheduled = updated ?? fallback;
    if (scheduled != null) {
      NotificationService.scheduleShiftEnd(
        scheduled.scheduledEnd,
        shiftRef: scheduled.displayRef,
      );
    }
  }

  /// Requests supervisor approval to end early. Does NOT end the shift — the
  /// shift keeps running (GPS, welfare checks) until approval arrives and the
  /// guard taps END. Rethrows on a failed submission so the sheet can report it.
  Future<void> requestEarlyEnd({
    required String reason,
    required String note,
  }) async {
    await ref.read(currentShiftProvider.notifier).requestEarlyEnd(
          reason: reason,
          note: note,
        );
  }

  Future<void> end({
    bool endedEarly = false,
    String? reason,
    String? note,
  }) async {
    final shiftId = state.id;
    if (shiftId == null) return;
    // Call the server first so 409 rejections (END_BEFORE_SCHEDULED,
    // EARLY_END_NOT_APPROVED) propagate to the caller before we tear down
    // local state. The GPS keeps running for the brief API round-trip, which
    // is correct — the shift is still active until the server confirms the end.
    await ref.read(currentShiftProvider.notifier).end(
      endedEarly: endedEarly,
      reason: reason,
      note: note,
    );
    // Server confirmed — tear down background work now.
    ref.read(gpsServiceProvider).stopCapture();
    NotificationService.cancelShiftEnd();
    ref.read(wakefulnessScheduleProvider.notifier).clear();
    ref.read(photoScheduleProvider.notifier).clear();
    ref.read(wakefulnessProvider.notifier).clearHistory();
    ref.read(locationDeniedProvider.notifier).set(false);
    state = const ShiftState();
  }

  /// The server closed the shift on its side while the app still showed it
  /// active — an **auto-close** at `scheduled_end + grace` (`end_type: auto`),
  /// or an admin **cancel**. Tears down the in-progress work exactly like
  /// [end] but **without** calling `POST /end` (the shift is already closed
  /// server-side; ending again would `409 SHIFT_NOT_ENDABLE`). Idempotent —
  /// a no-op once we're inactive, so it can't double-fire or clash with a
  /// guard-initiated end (which has already set `active = false` by the time its
  /// completed shift arrives, and is `end_type: guard`/`early` anyway).
  void reconcileServerClosed() {
    if (!state.active) return;
    ref.read(gpsServiceProvider).stopCapture();
    NotificationService.cancelShiftEnd();
    ref.read(wakefulnessScheduleProvider.notifier).clear();
    ref.read(photoScheduleProvider.notifier).clear();
    ref.read(wakefulnessProvider.notifier).clearHistory();
    ref.read(locationDeniedProvider.notifier).set(false);
    state = const ShiftState();
  }

  /// Called when the server reports a shift as active but local state shows
  /// inactive (e.g. app restart while a shift is in progress, or a parse
  /// error left state out of sync after a successful POST /start).
  void resumeFromServer(CurrentShiftModel shift) {
    if (state.active) return;
    state = ShiftState(
      active: true,
      startTime: shift.actualStart ?? DateTime.now(),
      id: shift.id,
      shiftRef: shift.displayRef,
    );
    ref.read(gpsServiceProvider).startCapture(shift.id).then(
          (tracking) =>
              ref.read(locationDeniedProvider.notifier).set(!tracking),
        );
    // Re-arm the wakefulness TOTP schedule + offline-photo schedule from secure
    // storage (the start response isn't replayed on resume).
    ref.read(wakefulnessScheduleProvider.notifier).restore();
    ref.read(photoScheduleProvider.notifier).restore();

    // Re-arm the end-of-shift reminder (e.g. after an app relaunch mid-shift).
    NotificationService.scheduleShiftEnd(
      shift.scheduledEnd,
      shiftRef: shift.displayRef,
    );
  }

  void recordWelfareCheck({required bool passed}) {
    state = state.copyWith(
      welfareChecksTotal: state.welfareChecksTotal + 1,
      welfareChecksPassed: passed
          ? state.welfareChecksPassed + 1
          : state.welfareChecksPassed,
    );
  }

  void recordPhoto({required bool passed}) {
    state = state.copyWith(
      photosTotal: state.photosTotal + 1,
      photosPassed: passed ? state.photosPassed + 1 : state.photosPassed,
    );
  }
}

final shiftProvider =
    NotifierProvider<ShiftNotifier, ShiftState>(ShiftNotifier.new);
