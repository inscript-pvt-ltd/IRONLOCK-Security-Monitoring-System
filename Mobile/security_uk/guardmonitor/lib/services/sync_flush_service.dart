import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/api_config.dart';
import '../data/offline_queue_db.dart';
import '../providers/shift_provider.dart';
import 'api_client.dart';
import 'photo_service.dart';
import 'sync_retry.dart';
import 'wakefulness_service.dart';

/// Orchestrates draining the offline queue to the (idempotent) server endpoints
/// when connectivity returns. This is the Phase 7 flush engine.
///
/// Responsibilities:
/// - **Trigger:** on a false→true connectivity transition, on an important
///   enqueue (see below), on a periodic heartbeat, and on demand. An empty queue
///   is always a cheap no-op, so over-triggering is harmless.
/// - **Order:** wakefulness → photos → GPS, oldest first. The server tolerates
///   any order (PHASE_7_SYNC_INTEGRITY.md §3); we deliberately flush the
///   compliance-critical events (welfare answers, then proof photos) **before**
///   the high-volume GPS trail so they reach the dashboard first on reconnect
///   instead of queueing behind a long breadcrumb backlog.
/// - **Single-flight:** overlapping triggers (connectivity flaps) coalesce onto
///   one in-progress flush instead of racing.
/// - **Retry:** each item's outcome runs through [classifyFlush] — success/drop
///   dequeue, retryable bumps the backoff gate, and an item past
///   [kMaxFlushAttempts] is dropped so it can't pin the queue.
///
/// GPS is implemented here; wakefulness and photo flush bodies land in Stages
/// 4–5. An empty queue is always a no-op that never throws.
class SyncFlushService {
  SyncFlushService(this._db, this._dio, this._currentShiftId);

  final OfflineQueueDb _db;
  final Dio _dio;
  final String? Function() _currentShiftId;

  late final WakefulnessService _wakefulness = WakefulnessService(_dio);
  late final PhotoService _photo = PhotoService(_dio);

  /// Max GPS pings per POST. The server has no hard cap but processes each ping
  /// synchronously (geofence + UPSERT), so a long offline backlog is chunked to
  /// avoid a request timeout (backend guidance: 100–200).
  static const int _gpsBatchSize = 200;

  /// Tags every reconnect-drain GPS POST with `backfill:true` so the server
  /// forces its reconnect/replay classification for **all** chunks of a
  /// multi-request backlog — not just the first. Without it, applying chunk #1
  /// refreshes the server's last-seen row, so chunk #2 of a >200-ping backlog is
  /// misread as a live tick and can retroactively page a supervisor (backend
  /// reply 2026-07-23 §5). Every `_flushGps` batch is a backfill by definition:
  /// live pings post directly from `GpsService` and only reach the queue after a
  /// failed live delivery.
  ///
  /// ⚠️ Held OFF until the backend confirms the server honours the field. It
  /// ignores it today, so sending changes nothing — Jerry asked us not to send
  /// until it's live. Flip to `true` + redeploy on his go-live confirmation; no
  /// other change is needed.
  static const bool sendGpsBackfillFlag = false;

  /// Backstop cadence: while signed in, attempt a flush every [_heartbeatInterval]
  /// even with no connectivity edge. Covers the case where the online flag never
  /// flips but individual requests are failing (congested tower / server blip) —
  /// without it, an item enqueued during such a "soft" failure could sit until the
  /// next app-resume. An empty queue makes this a no-op.
  static const Duration _heartbeatInterval = Duration(seconds: 60);

  StreamSubscription<bool>? _sub;
  StreamSubscription<void>? _enqueueSub;
  Timer? _heartbeat;
  bool _wasOnline = true;
  Future<void>? _inFlight;

  /// Number of completed flush cycles — for tests/diagnostics only.
  @visibleForTesting
  int debugFlushCount = 0;

  /// Begins watching [onlineStream] and flushes whenever the device transitions
  /// from offline to online. Call after sign-in. Idempotent.
  void start(Stream<bool> onlineStream) {
    _sub?.cancel();
    _sub = onlineStream.listen((online) {
      final reconnected = online && !_wasOnline;
      _wasOnline = online;
      if (reconnected) unawaited(flush());
    });
    // Enqueue-kick: the moment a compliance-critical capture (welfare answer /
    // proof photo) is buffered, try to drain it immediately instead of waiting
    // for the next connectivity edge. single-flight coalesces this onto any run
    // already in progress, and a genuinely offline attempt just fails fast and
    // re-queues, so it's safe to fire on every important enqueue.
    _enqueueSub?.cancel();
    _enqueueSub = _db.onImportantEnqueue.listen((_) => unawaited(flush()));
    // Heartbeat backstop (see _heartbeatInterval).
    _heartbeat?.cancel();
    _heartbeat = Timer.periodic(_heartbeatInterval, (_) => unawaited(flush()));
    // Drain any backlog left from a previous session (e.g. the app was killed
    // mid-shift with queued pings, then relaunched already online — no
    // offline→online edge would otherwise fire). Cheap no-op if empty.
    unawaited(flush());
  }

  /// Stops watching connectivity + enqueue signals + the heartbeat. Call on
  /// sign-out.
  void stop() {
    _sub?.cancel();
    _sub = null;
    _enqueueSub?.cancel();
    _enqueueSub = null;
    _heartbeat?.cancel();
    _heartbeat = null;
  }

  /// Drains the queue once. Concurrent calls share the single in-flight run
  /// (single-flight), so a connectivity flap can't launch overlapping flushes.
  /// Never throws — a background flush must not crash the app.
  Future<void> flush() {
    return _inFlight ??= _runCycle().whenComplete(() => _inFlight = null);
  }

  /// True when a flush error means the **server never answered** (offline,
  /// connection dropped, timeout) rather than actively responding. Such a failure
  /// must NOT count against a row's retry budget — being offline is "not yet", not
  /// a reason to give up on a compliance-critical capture. A real server response
  /// (5xx) has `response != null` and still counts.
  static bool _noServerResponse(Object? error) =>
      error is DioException && error.response == null;

  Future<void> _runCycle() async {
    try {
      // Don't even attempt while the device reports offline: an offline "failure"
      // isn't a real failure, and hammering the queue offline used to erode each
      // row's retry budget. Wait for a real connection, then push (the reconnect
      // edge fires this). Belt-and-braces with the per-item "no response = no
      // strike" handling below, which covers a connected-but-server-unreachable
      // case that the OS connectivity flag can't see.
      if (!_wasOnline) {
        if (kDebugMode) debugPrint('[sync] skip — device offline, will push on reconnect');
        return;
      }
      final shiftId = _currentShiftId();
      if (kDebugMode) {
        final wake = await _db.dueWakefulness(DateTime.now().millisecondsSinceEpoch);
        debugPrint('[sync] flush start → base=${ApiConfig.baseUrl} '
            'shift=$shiftId wakefulnessDue=${wake.length}');
      }
      // Compliance-critical first (welfare answers, then proof photos), bulk GPS
      // last — so a long GPS backlog can't delay the events supervisors watch.
      // All three flush by each row's OWN shiftId (not the live shift), so a
      // backlog still drains after the shift ends mid-flush (H1). `shiftId` above
      // is used only for the debug line.
      await _flushWakefulness();
      await _flushPhotos();
      await _flushGps();
      if (kDebugMode) debugPrint('[sync] flush done');
    } catch (error, stack) {
      // A flush must be best-effort: swallow anything unexpected so the app and
      // the connectivity listener keep running. Per-item failures are handled
      // inside each flusher via the retry table.
      if (kDebugMode) {
        debugPrint('[sync] flush cycle error: $error\n$stack');
      }
    } finally {
      debugFlushCount++;
    }
  }

  // ---- GPS -----------------------------------------------------------------

  Future<void> _flushGps() async {
    final nowMs = DateTime.now().millisecondsSinceEpoch;
    // ALL shifts, not just the live one — a backlog must still drain after its
    // shift ended (H1). Grouped by each row's own shiftId (the /locations POST is
    // per-shift). LinkedHashMap preserves the oldest-first order within a shift.
    final due = await _db.dueGpsAll(nowMs);
    if (due.isEmpty) return;
    final byShift = <String, List<GpsQueueData>>{};
    for (final r in due) {
      (byShift[r.shiftId] ??= <GpsQueueData>[]).add(r);
    }
    for (final entry in byShift.entries) {
      if (await _flushGpsForShift(entry.key, entry.value, nowMs)) return;
    }
  }

  /// Flushes one shift's due GPS rows in ≤200-ping batches. Returns true if the
  /// caller should STOP the whole cycle (a transient failure = network down, so
  /// other shifts would just fail too); false when this shift drained cleanly.
  Future<bool> _flushGpsForShift(
    String shiftId,
    List<GpsQueueData> due,
    int nowMs,
  ) async {
    // Drop rows that have exhausted their retries before they can wedge a batch.
    final exhausted =
        due.where((r) => r.attempts >= kMaxFlushAttempts).map((r) => r.id).toList();
    if (exhausted.isNotEmpty) await _db.deleteGps(exhausted);
    final live = due.where((r) => r.attempts < kMaxFlushAttempts).toList();

    for (var i = 0; i < live.length; i += _gpsBatchSize) {
      final batch = live.sublist(i, (i + _gpsBatchSize).clamp(0, live.length));
      final ids = batch.map((r) => r.id).toList();
      final pings = batch
          .map((r) => {
                'latitude': r.latitude,
                'longitude': r.longitude,
                if (r.accuracy != null) 'accuracy': r.accuracy,
                if (r.battery != null) 'battery': r.battery,
                'recorded_at': r.recordedAt,
              })
          .toList();

      Object? error;
      try {
        await _dio.post<Map<String, dynamic>>(
          ApiConfig.shiftLocations(shiftId),
          data: {
            'pings': pings,
            // A reconnect-drain replay, not a live tick — see sendGpsBackfillFlag.
            if (sendGpsBackfillFlag) 'backfill': true,
          },
        );
      } catch (e) {
        error = e;
      }

      final decision = classifyFlush(error);
      if (kDebugMode) {
        debugPrint('[sync] gps → POST ${ApiConfig.shiftLocations(shiftId)} '
            'batch=${batch.length} → ${decision.action.name}'
            '${error == null ? '' : ' ($error)'}');
      }
      switch (decision.action) {
        case FlushAction.success:
        case FlushAction.drop:
          await _db.deleteGps(ids);
        case FlushAction.retry:
          if (_noServerResponse(error)) {
            // Offline / no response = "not yet", not a strike. Gate to now so a
            // reconnect flushes immediately, WITHOUT burning the retry budget.
            await _db.gateGps(ids, nowMs);
          } else {
            // A real server 5xx counts — gate behind the backoff of the batch's
            // most-tried row.
            final maxAttempts =
                batch.map((r) => r.attempts).reduce((a, b) => a > b ? a : b);
            final next = nowMs + backoffDelay(maxAttempts).inMilliseconds;
            await _db.bumpGpsAttempts(ids, next);
          }
          // Stop the whole cycle on a transient failure — the network is down
          // again; later batches (and other shifts) would just fail too. The
          // next reconnect retries.
          return true;
      }
    }
    return false; // this shift drained cleanly — continue with the next.
  }

  // ---- Wakefulness ---------------------------------------------------------

  Future<void> _flushWakefulness() async {
    final nowMs = DateTime.now().millisecondsSinceEpoch;
    final due = await _db.dueWakefulness(nowMs);
    for (final row in due) {
      if (row.attempts >= kMaxFlushAttempts) {
        await _db.deleteWakefulness(row.id);
        continue;
      }
      Object? error;
      try {
        await _wakefulness.submitOffline(
          shiftId: row.shiftId,
          code: row.code,
          windowReference: row.windowReference,
          respondedAt: row.respondedAt,
          scheduledAt: row.scheduledAt,
        );
      } catch (e) {
        error = e;
      }
      final decision = classifyFlush(error);
      if (kDebugMode) {
        debugPrint('[sync] wakefulness → POST '
            '${ApiConfig.wakefulnessOffline(row.shiftId)} '
            'window=${row.windowReference} → ${decision.action.name}'
            '${error == null ? '' : ' ($error)'}');
      }
      switch (decision.action) {
        case FlushAction.success:
        case FlushAction.drop:
          await _db.deleteWakefulness(row.id);
        case FlushAction.retry:
          if (_noServerResponse(error)) {
            // Offline / no response — keep the answer, gate to now, DON'T strike.
            await _db.gateWakefulness(row.id, nowMs);
          } else {
            final next = nowMs + backoffDelay(row.attempts).inMilliseconds;
            await _db.bumpWakefulness(row.id, next);
          }
          // Transient failure — the network is down again; remaining rows would
          // just burn a timeout each. Stop; the next reconnect retries. (Mirrors
          // _flushGps; a per-row 4xx is a `drop`, not a `retry`, so it doesn't
          // stop the loop.)
          return;
      }
    }
  }

  // ---- Photos --------------------------------------------------------------

  Future<void> _flushPhotos() async {
    final nowMs = DateTime.now().millisecondsSinceEpoch;
    // ALL shifts — proof photos must still drain after their shift ends (H1).
    // Each row carries its own shiftId, so the per-shift POST just uses it.
    final due = await _db.duePhotosAll(nowMs);
    for (final row in due) {
      final shiftId = row.shiftId;
      if (row.attempts >= kMaxFlushAttempts) {
        await _discardPhoto(row);
        continue;
      }
      final paths = (jsonDecode(row.filePaths) as List).cast<String>();
      final sigs = (jsonDecode(row.signatures) as List).cast<String>();

      Object? error;
      try {
        await _photo.submitOfflinePhotos(
          shiftId: shiftId,
          nonceValue: row.nonceValue,
          filePaths: paths,
          signatures: sigs,
          capturedAt: row.capturedAt,
          ntpReference: row.ntpReference,
          elapsedSeconds: row.elapsedSeconds,
          // Re-send the exact lat/lng strings the signature was computed over.
          latitude: row.latitude?.toString() ?? '',
          longitude: row.longitude?.toString() ?? '',
        );
      } catch (e) {
        error = e;
      }

      final decision = classifyFlush(error);
      if (kDebugMode) {
        debugPrint('[sync] photo → POST ${ApiConfig.shiftPhotos(shiftId)} '
            'nonce=${row.nonceValue} → ${decision.action.name}'
            '${error == null ? '' : ' ($error)'}');
      }
      switch (decision.action) {
        case FlushAction.success:
        case FlushAction.drop:
          await _discardPhoto(row); // also deletes the durable files
        case FlushAction.retry:
          if (_noServerResponse(error)) {
            // Offline / no response — keep the photo, gate to now, DON'T strike.
            await _db.gatePhoto(row.id, nowMs);
          } else {
            final next = nowMs + backoffDelay(row.attempts).inMilliseconds;
            await _db.bumpPhoto(row.id, next);
          }
          // Transient failure — stop the cycle (network down); next reconnect
          // retries. A per-row 422 rejection is a `drop`, not a `retry`.
          return;
      }
    }
  }

  /// Deletes a photo row's durable files, then the row. Best-effort on the files.
  Future<void> _discardPhoto(PhotoQueueData row) async {
    try {
      for (final path in (jsonDecode(row.filePaths) as List).cast<String>()) {
        final f = File(path);
        if (await f.exists()) await f.delete();
      }
    } catch (_) {}
    await _db.deletePhoto(row.id);
  }
}

/// App-wide flush engine. Constructed once; `start()`/`stop()` are driven by the
/// auth lifecycle in `main.dart`. `currentShiftId` returns the active shift id
/// (or null when no shift is running) so GPS/photo flushes target the right shift.
final syncFlushServiceProvider = Provider<SyncFlushService>((ref) {
  final service = SyncFlushService(
    ref.read(offlineQueueDbProvider),
    ref.read(dioProvider),
    () {
      final shift = ref.read(shiftProvider);
      return shift.active ? shift.id : null;
    },
  );
  ref.onDispose(service.stop);
  return service;
});
