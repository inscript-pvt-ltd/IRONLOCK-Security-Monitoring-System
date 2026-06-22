import 'dart:math';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/current_shift_model.dart';
import '../services/gps_service.dart';
import '../services/notification_service.dart';
import '../services/shift_service.dart';

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
      state = result;
    } catch (_) {
      // Not critical — guard can still see the cached state; next poll/resume retries.
    }
  }

  /// Calls `POST /shifts/{id}/start`. Throws [DioException] on
  /// `409 SHIFT_NOT_STARTABLE` etc. so the caller can surface the error.
  /// The response is partial (id, status, actual_start, can_end only) so we
  /// merge the changed fields into the existing full state from GET /shifts/current.
  Future<CurrentShiftModel> start() async {
    final current = state;
    if (current == null) throw StateError('No current shift to start.');
    final actualStart =
        await ref.read(shiftServiceProvider).startShift(current.id);
    final updated = CurrentShiftModel(
      id: current.id,
      reference: current.reference,
      status: 'active',
      scheduledStart: current.scheduledStart,
      scheduledEnd: current.scheduledEnd,
      canStart: false,
      canEnd: true,
      actualStart: actualStart,
      site: current.site,
      geofence: current.geofence,
      role: current.role,
      notes: current.notes,
    );
    state = updated;
    return updated;
  }

  /// Calls `POST /shifts/{id}/end`. Throws [DioException] on
  /// `409 SHIFT_NOT_ENDABLE` etc. so the caller can surface the error.
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
      actualStart: result.actualStart ?? current.actualStart,
      actualEnd: result.actualEnd,
      durationHours: result.durationHours,
      site: current.site,
      geofence: current.geofence,
      role: current.role,
      notes: current.notes,
    );
    state = updated;
    return updated;
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
      ref.read(gpsServiceProvider).startCapture(shiftId);
    }
    _generateNoncePool();

    // Schedule the "shift ended" reminder for the scheduled end time.
    final scheduled = updated ?? fallback;
    if (scheduled != null) {
      NotificationService.scheduleShiftEnd(
        scheduled.scheduledEnd,
        shiftRef: scheduled.displayRef,
      );
    }
  }

  Future<void> end({
    bool endedEarly = false,
    String? reason,
    String? note,
  }) async {
    final shiftId = state.id;
    ref.read(gpsServiceProvider).stopCapture();
    // The shift is ending — cancel the pending "shift ended" reminder so it
    // can't fire for an already-closed shift.
    NotificationService.cancelShiftEnd();
    state = const ShiftState();

    if (shiftId != null) {
      try {
        await ref.read(currentShiftProvider.notifier).end(
              endedEarly: endedEarly,
              reason: reason,
              note: note,
            );
      } catch (_) {}
    }
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
    ref.read(gpsServiceProvider).startCapture(shift.id);
    _generateNoncePool();

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

  /// No nonce-issuing endpoint exists in the contract (gap flagged to the
  /// backend dev) — nonces are generated client-side instead of fetched.
  void _generateNoncePool() {
    final random = Random.secure();
    final nonces = List.generate(15, (_) {
      final bytes = List<int>.generate(16, (_) => random.nextInt(256));
      return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
    });
    ref.read(noncePoolProvider.notifier).load(nonces);
  }
}

final shiftProvider =
    NotifierProvider<ShiftNotifier, ShiftState>(ShiftNotifier.new);

// ── Nonce pool (generated locally at shift start, consumed per photo) ─────

class NoncePoolNotifier extends Notifier<List<String>> {
  @override
  List<String> build() => const [];

  void load(List<String> nonces) => state = List.unmodifiable(nonces);

  String? consume() {
    if (state.isEmpty) return null;
    final nonce = state.first;
    state = List.unmodifiable(state.sublist(1));
    return nonce;
  }

  bool get isEmpty => state.isEmpty;
}

final noncePoolProvider =
    NotifierProvider<NoncePoolNotifier, List<String>>(NoncePoolNotifier.new);
