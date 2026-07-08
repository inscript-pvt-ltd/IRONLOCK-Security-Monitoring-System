import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import 'api_client.dart';

class WakefulnessService {
  const WakefulnessService(this._dio);
  final Dio _dio;

  /// Returns `true` if the code was correct (`PASSED`), `false` otherwise.
  /// For an offline (locally-computed TOTP) challenge, pass [windowReference]
  /// and set [isOffline] so the server can validate a code generated for an
  /// earlier time step.
  Future<bool> respond(
    String checkId,
    String code, {
    int? windowReference,
    bool isOffline = false,
  }) async {
    // Stamp the response time once, before any retry, so a retried POST still
    // reports when the guard actually answered — not when the retry fired.
    final body = {
      'code': code,
      'responded_at': DateTime.now().toUtc().toIso8601String(),
      if (isOffline) ...{
        'is_offline': true,
        'window_reference': ?windowReference,
      },
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

  /// Single-attempt offline replay used by the Phase 7 flush engine for a queued
  /// answer. Posts the stored answer verbatim (the trusted [windowReference] and
  /// the original [respondedAt]) and returns normally on success. Unlike
  /// [respond] it does **not** swallow 4xx or retry internally — it rethrows the
  /// `DioException` so the queue's own retry table ([classifyFlush]) can tell
  /// `ALREADY_RESOLVED` (success) from a terminal rejection, and owns backoff.
  Future<void> submitOffline({
    required String checkId,
    required String code,
    required int windowReference,
    required String respondedAt,
  }) async {
    await _dio.post<Map<String, dynamic>>(
      ApiConfig.wakefulnessRespond(checkId),
      data: {
        'code': code,
        'is_offline': true,
        'window_reference': windowReference,
        'responded_at': respondedAt,
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
