import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../config/api_config.dart';
import '../data/offline_queue_db.dart';
import 'api_client.dart';

/// Manages the pre-fetched pool of single-use `OFFLINE_POOL` nonces that lets a
/// photo be captured + HMAC-signed while offline. The pool is topped up
/// opportunistically **while online** (shift start, after each online photo) and
/// drawn from when a capture happens with no connectivity.
///
/// Each nonce carries its **own** server-issued `expires_at` (stored per-row) and
/// that is the authoritative validity deadline — as of the 2026-07-23 backend fix
/// a pool nonce prefetched during a shift stays valid until end-of-shift (+grace),
/// so one prefetch at start covers every 50–70-min photo mark. (The old fixed
/// 15-min TTL predated Option A's mark spacing and always expired first.)
///
/// Backed by the encrypted [OfflineQueueDb] `NoncePool` table — draws are atomic
/// (single-use) and expiry is enforced on every read against each nonce's
/// `expires_at`.
class NoncePoolService {
  NoncePoolService(this._dio, this._db);

  final Dio _dio;
  final OfflineQueueDb _db;

  /// Pool depth at or below which [refillIfLow] fetches more.
  static const int kRefillThreshold = 5;

  /// How many nonces to request per top-up (server default 20, clamps 1–50).
  static const int kPrefetchCount = 20;

  /// Tops up the pool if it's running low. Online-only and best-effort: a
  /// network failure or `409 SHIFT_NOT_ACTIVE` just leaves the existing pool in
  /// place. Purges expired nonces first so the count is honest.
  Future<void> refillIfLow(
    String shiftId, {
    int threshold = kRefillThreshold,
    int count = kPrefetchCount,
  }) async {
    final nowMs = DateTime.now().millisecondsSinceEpoch;
    await _db.purgeExpiredNonces(nowMs);
    final depth = await _db.availableNonceCount(shiftId, nowMs);
    if (depth >= threshold) {
      if (kDebugMode) debugPrint('[nonce] pool ok (depth=$depth) — no refill');
      return;
    }

    try {
      final res = await _dio.post<Map<String, dynamic>>(
        ApiConfig.shiftNoncesPrefetch(shiftId),
        data: {'count': count},
      );
      final companions = parsePrefetch(res.data, shiftId);
      if (companions.isNotEmpty) await _db.insertNonces(companions);
      if (kDebugMode) {
        debugPrint('[nonce] prefetch → parsed ${companions.length} nonce(s) '
            '(was depth=$depth). If 0, the response shape did not match '
            '{data:{nonces:[{nonce_value,expires_at}]}}.');
      }
    } catch (e) {
      // Offline / shift not active / endpoint error — keep the existing pool.
      // Logged so a silently-failing prefetch (wrong shape, 4xx) is visible
      // instead of masquerading as an empty pool.
      if (kDebugMode) debugPrint('[nonce] prefetch FAILED (depth stays $depth): $e');
    }
  }

  /// Claims one unexpired, unused nonce for an offline capture (marks it used),
  /// or null if the pool is dry.
  Future<String?> draw(String shiftId) =>
      _db.drawNonce(shiftId, DateTime.now().millisecondsSinceEpoch);

  /// Currently usable pool depth for [shiftId].
  Future<int> available(String shiftId) =>
      _db.availableNonceCount(shiftId, DateTime.now().millisecondsSinceEpoch);

  /// Parses the `/nonces/prefetch` response body into storable rows. Pure +
  /// tolerant of a missing/odd shape. Public for unit testing.
  /// Shape: `{ data: { nonces: [ { nonce_value, expires_at, type } ] } }`.
  static List<NoncePoolCompanion> parsePrefetch(
    Map<String, dynamic>? body,
    String shiftId,
  ) {
    final data = body?['data'];
    if (data is! Map) return const [];
    final list = data['nonces'];
    if (list is! List) return const [];

    final out = <NoncePoolCompanion>[];
    for (final raw in list) {
      if (raw is! Map) continue;
      final value = raw['nonce_value'];
      final expiresRaw = raw['expires_at'];
      if (value is! String || expiresRaw is! String) continue;
      final expires = DateTime.tryParse(expiresRaw);
      if (expires == null) continue;
      out.add(NoncePoolCompanion.insert(
        nonceValue: value,
        shiftId: shiftId,
        expiresAt: expires.toUtc().millisecondsSinceEpoch,
      ));
    }
    return out;
  }
}

final noncePoolServiceProvider = Provider<NoncePoolService>(
  (ref) => NoncePoolService(ref.read(dioProvider), ref.read(offlineQueueDbProvider)),
);
