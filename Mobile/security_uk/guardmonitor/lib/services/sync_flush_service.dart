import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/api_config.dart';
import '../data/offline_queue_db.dart';
import '../providers/shift_provider.dart';
import 'api_client.dart';
import 'sync_retry.dart';

/// Orchestrates draining the offline queue to the (idempotent) server endpoints
/// when connectivity returns. This is the Phase 7 flush engine.
///
/// Responsibilities:
/// - **Trigger:** on a false→true connectivity transition (and on demand), run a
///   flush. Also safe to call periodically — an empty queue is a cheap no-op.
/// - **Order:** wakefulness → GPS → photos, oldest first. The server tolerates
///   any order (PHASE_7_SYNC_INTEGRITY.md §3); this is for our own coherence.
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

  /// Max GPS pings per POST. The server has no hard cap but processes each ping
  /// synchronously (geofence + UPSERT), so a long offline backlog is chunked to
  /// avoid a request timeout (backend guidance: 100–200).
  static const int _gpsBatchSize = 200;

  StreamSubscription<bool>? _sub;
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
    // Drain any backlog left from a previous session (e.g. the app was killed
    // mid-shift with queued pings, then relaunched already online — no
    // offline→online edge would otherwise fire). Cheap no-op if empty.
    unawaited(flush());
  }

  /// Stops watching connectivity. Call on sign-out.
  void stop() {
    _sub?.cancel();
    _sub = null;
  }

  /// Drains the queue once. Concurrent calls share the single in-flight run
  /// (single-flight), so a connectivity flap can't launch overlapping flushes.
  /// Never throws — a background flush must not crash the app.
  Future<void> flush() {
    return _inFlight ??= _runCycle().whenComplete(() => _inFlight = null);
  }

  Future<void> _runCycle() async {
    try {
      final shiftId = _currentShiftId();
      await _flushWakefulness();
      if (shiftId != null) {
        await _flushGps(shiftId);
        await _flushPhotos(shiftId);
      }
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

  Future<void> _flushGps(String shiftId) async {
    final nowMs = DateTime.now().millisecondsSinceEpoch;
    final due = await _db.dueGps(shiftId, nowMs);
    if (due.isEmpty) return;

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
          data: {'pings': pings},
        );
      } catch (e) {
        error = e;
      }

      final decision = classifyFlush(error);
      switch (decision.action) {
        case FlushAction.success:
        case FlushAction.drop:
          await _db.deleteGps(ids);
        case FlushAction.retry:
          // Gate the whole batch behind the backoff of its most-tried row.
          final maxAttempts =
              batch.map((r) => r.attempts).reduce((a, b) => a > b ? a : b);
          final next = nowMs + backoffDelay(maxAttempts).inMilliseconds;
          await _db.bumpGpsAttempts(ids, next);
          // Stop this cycle on a transient failure — the network is down again;
          // later batches would just fail too. The next reconnect retries.
          return;
      }
    }
  }

  // ---- Wakefulness (Stage 4) ----------------------------------------------

  Future<void> _flushWakefulness() async {
    // Stage 4.
  }

  // ---- Photos (Stage 5) ----------------------------------------------------

  Future<void> _flushPhotos(String shiftId) async {
    // Stage 5.
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
