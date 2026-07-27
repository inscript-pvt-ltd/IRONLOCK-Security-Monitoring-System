import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import 'api_client.dart';

class WakefulnessService {
  const WakefulnessService(this._dio);
  final Dio _dio;

  /// Returns `true` if the code was correct (`PASSED`), `false` otherwise.
  /// Online-only: [checkId] must be a real server check id from a push or the
  /// pending poll. A schedule-fired (TOTP) challenge has no such id — flush it
  /// via [submitOffline] instead.
  Future<bool> respond(String checkId, String code) async {
    // Stamp the response time once, before any retry, so a retried POST still
    // reports when the guard actually answered — not when the retry fired.
    final body = {
      'code': code,
      'responded_at': DateTime.now().toUtc().toIso8601String(),
    };

    DioException? lastError;
    for (var attempt = 0; attempt < 3; attempt++) {
      try {
        final response = await _dio.post<Map<String, dynamic>>(
          ApiConfig.wakefulnessRespond(checkId),
          data: body,
        );
        final apiResponse = ApiResponse.fromJson(
          response.data!,
          (data) => (data as Map<String, dynamic>)['result'] as String,
        );
        return apiResponse.data == 'PASSED';
      } on DioException catch (e) {
        final status = e.response?.statusCode;
        // A 4xx is the server's authoritative rejection (e.g. 422 expired /
        // wrong code) — a verdict, not a transport failure. Don't retry it;
        // report it as a miss.
        if (status != null && status >= 400 && status < 500) return false;
        // 5xx or no response (timeout / connection drop) — a transient blip a
        // correct answer shouldn't be lost to. Back off and retry.
        lastError = e;
        if (attempt < 2) {
          await Future<void>.delayed(Duration(milliseconds: 300 * (attempt + 1)));
        }
      }
    }
    throw lastError!;
  }

  /// Flushes a wakefulness answer that fired on-device from the TOTP schedule —
  /// it has **no** server `check_id`, so it goes to
  /// `POST /shifts/{shiftId}/wakefulness/offline`, keyed on the absolute
  /// [windowReference]. The server re-derives the TOTP for that window and
  /// records the check (pass or fail). Idempotent per (shift, window_reference):
  /// a re-flush returns `reason: "ALREADY_RESOLVED"` on a 200.
  ///
  /// Used both by the immediate (online) path and by the Phase 7 flush engine on
  /// reconnect. Unlike [respond] it does **not** swallow 4xx or retry internally
  /// — it rethrows the `DioException` so the queue's own retry table
  /// ([classifyFlush]) can tell a terminal rejection from a transient blip and
  /// owns backoff. Any 200 (including `ALREADY_RESOLVED`) is "done".
  Future<void> submitOffline({
    required String shiftId,
    required String code,
    required int windowReference,
    required String respondedAt,
    String? scheduledAt,
  }) async {
    await _dio.post<Map<String, dynamic>>(
      ApiConfig.wakefulnessOffline(shiftId),
      data: {
        'window_reference': windowReference,
        'code': code,
        'responded_at': respondedAt,
        'scheduled_at': ?scheduledAt,
      },
    );
  }

  /// Fire-and-forget confirmation that an online wakefulness push arrived
  /// (Phase 6). Lets the server tell "guard ignored it" from "push never
  /// landed". Online/push-only — never call it for an offline TOTP challenge.
  Future<void> confirmReceived(String checkId) async {
    // Best-effort with a small retry — a dropped receipt risks a false alarm,
    // so it's worth a couple of attempts, but never fatal.
    for (var attempt = 0; attempt < 2; attempt++) {
      try {
        await _dio.post<void>(ApiConfig.wakefulnessReceived(checkId));
        return;
      } catch (_) {
        if (attempt == 0) {
          await Future<void>.delayed(const Duration(milliseconds: 400));
        }
      }
    }
  }
}

final wakefulnessServiceProvider = Provider<WakefulnessService>(
  (ref) => WakefulnessService(ref.read(dioProvider)),
);
