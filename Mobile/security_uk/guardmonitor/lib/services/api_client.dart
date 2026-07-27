import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import 'device_info_service.dart';
import 'secure_storage_service.dart';

/// Force the HTTP logger on in ANY build mode (incl. `--release`) with
/// `--dart-define=HTTP_LOG=true`. Debug builds log unconditionally; this exists
/// so a release build under test can surface backend traffic in the `flutter run`
/// console. Still never logs bodies/headers — only method/path/status/error-code.
const bool _kForceHttpLog = bool.fromEnvironment('HTTP_LOG');

final dioProvider = Provider<Dio>((ref) {
  final dio = Dio(BaseOptions(
    baseUrl: ApiConfig.baseUrl,
    connectTimeout: ApiConfig.connectTimeout,
    receiveTimeout: ApiConfig.receiveTimeout,
    headers: const {
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-App-Version': '4.0',
      'X-Platform': 'mobile',
    },
  ));

  dio.interceptors.add(_JwtInterceptor(dio, ref));
  // Track whether the backend is actually *reachable* (not just whether a
  // network interface is up). Feeds `serverReachableProvider`. Placed after the
  // JWT interceptor so it observes the final outcome incl. refresh-retries.
  dio.interceptors.add(_ReachabilityInterceptor(ref));
  // Log every request/response/error in one place so ALL backend traffic is
  // visible in the console. Added last so it sees the final outcome (incl.
  // anything the JWT interceptor passes through). On in debug automatically, or
  // in ANY mode via `--dart-define=HTTP_LOG=true` (see [_kForceHttpLog]).
  if (kDebugMode || _kForceHttpLog) dio.interceptors.add(_DebugLogInterceptor());

  return dio;
});

/// True while the backend answers our requests; false when the interface claims
/// to be up but the server can't be reached (dead Wi-Fi, a captive portal that
/// never lets our API calls through, a black-hole cellular handover). Distinct
/// from [isOnlineProvider], which only reflects the OS network interface.
///
/// Why this exists: `connectivity_plus` reports "online" the instant a Wi-Fi
/// interface associates — even on a hotel/venue captive portal where no API
/// call ever completes. Gating the offline welfare/photo scheduler on the raw
/// interface flag then left a guard **silently un-prompted**: the local
/// scheduler was suppressed (thinks it's online) *and* every server poll failed,
/// so no challenge appeared at all. The scheduler is instead gated on
/// interface-online **AND** server-reachable (see `home_screen._pollBackend`),
/// so a "connected but unreachable" phone falls back to the offline path and
/// keeps prompting — and, because the server genuinely can't be reached, the
/// answer is correctly recorded via the offline endpoint (no mislabelling).
class ServerReachabilityNotifier extends Notifier<bool> {
  @override
  bool build() => true; // assume reachable until a request proves otherwise

  /// A request completed with an HTTP response (any status, even 4xx/5xx) → the
  /// server is reachable. A request that produced no response at all
  /// (connection error / timeout) → not reachable.
  void report({required bool reachable}) => state = reachable;
}

final serverReachableProvider =
    NotifierProvider<ServerReachabilityNotifier, bool>(ServerReachabilityNotifier.new);

/// Flips [serverReachableProvider] from real request outcomes. A response of any
/// status means the box answered (reachable); a null-response error means it
/// didn't (unreachable). The refresh POST inside [_JwtInterceptor] also flows
/// through here, so the signal stays fresh even during a token refresh.
class _ReachabilityInterceptor extends Interceptor {
  _ReachabilityInterceptor(this._ref);
  final Ref _ref;

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    _ref.read(serverReachableProvider.notifier).report(reachable: true);
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    // `response == null` ⇒ connection error / timeout / DNS failure — the server
    // never answered. A 4xx/5xx DOES carry a response, so the server IS reachable
    // (it just rejected the request), which must NOT read as offline.
    _ref
        .read(serverReachableProvider.notifier)
        .report(reachable: err.response != null);
    handler.next(err);
  }
}

/// Logs each HTTP call: method + path + status + (on error) the server error
/// code. Deliberately **never** logs request/response bodies or headers — they
/// carry the password, JWTs, and the photo `hmac_secret`. Debug builds only.
class _DebugLogInterceptor extends Interceptor {
  @override
  void onRequest(RequestOptions options, RequestInterceptorHandler handler) {
    debugPrint('[http] → ${options.method} ${options.uri}');
    handler.next(options);
  }

  @override
  void onResponse(Response<dynamic> response, ResponseInterceptorHandler handler) {
    final r = response.requestOptions;
    debugPrint('[http] ← ${response.statusCode} ${r.method} ${r.path}');
    handler.next(response);
  }

  @override
  void onError(DioException err, ErrorInterceptorHandler handler) {
    final data = err.response?.data;
    final code =
        (data is Map && data['error'] is Map) ? (data['error'] as Map)['code'] : null;
    debugPrint('[http] ✗ ${err.response?.statusCode ?? err.type.name} '
        '${err.requestOptions.method} ${err.requestOptions.path}'
        '${code != null ? ' code=$code' : ''}');
    handler.next(err);
  }
}

/// Holds the sign-out callback so [_JwtInterceptor] can route back to Login
/// when a refresh is terminally rejected, without `api_client.dart` importing
/// `app_providers.dart` (which would create a circular import). Wired by
/// `AuthNotifier.build()` in `app_providers.dart`.
class ForcedSignOutNotifier extends Notifier<void Function()?> {
  @override
  void Function()? build() => null;

  void set(void Function() callback) => state = callback;
}

final forcedSignOutCallbackProvider =
    NotifierProvider<ForcedSignOutNotifier, void Function()?>(ForcedSignOutNotifier.new);

class _JwtInterceptor extends Interceptor {
  _JwtInterceptor(this._dio, this._ref);
  final Dio _dio;
  final Ref _ref;

  // Guard against concurrent refresh calls
  bool _refreshing = false;
  final _pendingRetries = <({RequestOptions options, ErrorInterceptorHandler handler})>[];

  @override
  Future<void> onRequest(
    RequestOptions options,
    RequestInterceptorHandler handler,
  ) async {
    final token = await SecureStorageService.getToken();
    if (token != null) {
      options.headers['Authorization'] = 'Bearer $token';
    }
    handler.next(options);
  }

  @override
  Future<void> onError(
    DioException err,
    ErrorInterceptorHandler handler,
  ) async {
    final statusCode = err.response?.statusCode;
    final responseData = err.response?.data;
    String? errorCode;
    if (responseData is Map) {
      final error = responseData['error'];
      if (error is Map) errorCode = error['code'] as String?;
    }

    final isExpiredOrInvalidToken =
        statusCode == 401 && (errorCode == 'TOKEN_EXPIRED' || errorCode == 'TOKEN_INVALID');

    // Only attempt refresh on an expired/invalid access token; skip if this
    // IS the refresh call itself (that failure is terminal, not retryable).
    if (!isExpiredOrInvalidToken || err.requestOptions.path == ApiConfig.refresh) {
      handler.next(err);
      return;
    }

    if (_refreshing) {
      _pendingRetries.add((options: err.requestOptions, handler: handler));
      return;
    }

    _refreshing = true;
    try {
      final refreshToken = await SecureStorageService.getRefreshToken();
      if (refreshToken == null) {
        handler.next(err);
        _failPending(err);
        _ref.read(forcedSignOutCallbackProvider)?.call();
        return;
      }

      final refreshResponse = await _dio.post<Map<String, dynamic>>(
        ApiConfig.refresh,
        data: {
          'refresh_token': refreshToken,
          'device': {'device_id': await DeviceInfoService.getOrCreateDeviceId()},
        },
        options: Options(headers: {'Authorization': null}),
      );

      final data = refreshResponse.data?['data'] as Map<String, dynamic>?;
      final newToken = data?['access_token'] as String?;
      final newRefresh = data?['refresh_token'] as String?;
      final newExpiresAt = data?['expires_at'] as String?;

      if (newToken == null) {
        handler.next(err);
        _failPending(err);
        _ref.read(forcedSignOutCallbackProvider)?.call();
        return;
      }

      await SecureStorageService.saveToken(newToken);
      if (newRefresh != null) {
        await SecureStorageService.saveRefreshToken(newRefresh);
      }
      if (newExpiresAt != null) {
        final parsed = DateTime.tryParse(newExpiresAt);
        if (parsed != null) await SecureStorageService.saveExpiresAt(parsed);
      }

      // Retry the original request with the new token. Wrap in its own
      // try/catch: a failure here (4xx/5xx on the endpoint) is a real
      // request error, NOT a session failure — don't sign the user out.
      try {
        final retried = await _retry(err.requestOptions, newToken);
        handler.resolve(retried);
      } catch (retryErr) {
        handler.next(retryErr is DioException ? retryErr : err);
      }

      // Drain any requests that queued while we were refreshing.
      await _drainPending(newToken);
    } catch (_) {
      // Refresh itself failed (e.g. TOKEN_INVALID/TOKEN_EXPIRED on refresh) —
      // terminal: there's no token to recover with, force the user to Login.
      handler.next(err);
      _failPending(err);
      _ref.read(forcedSignOutCallbackProvider)?.call();
    } finally {
      // The drain/fail helpers pop as they go, so the list is normally empty
      // here; clear() only mops up the rare item added in the microsecond gap
      // before _refreshing flips, so it can't leak into the next refresh cycle.
      _pendingRetries.clear();
      _refreshing = false;
    }
  }

  /// Retries every request that queued while the refresh was in flight, with the
  /// fresh [token]. Pops one at a time (instead of a `for-in`) so a concurrent
  /// 401 appending to the list mid-drain can't raise a ConcurrentModificationError
  /// — which the outer catch would misread as a refresh failure and force a
  /// spurious sign-out. Each queued request reports its OWN outcome.
  Future<void> _drainPending(String token) async {
    while (_pendingRetries.isNotEmpty) {
      final pending = _pendingRetries.removeAt(0);
      try {
        final r = await _retry(pending.options, token);
        pending.handler.resolve(r);
      } catch (e) {
        pending.handler.next(
          e is DioException
              ? e
              : DioException(requestOptions: pending.options, error: e),
        );
      }
    }
  }

  /// Rejects every queued request when the refresh itself failed, so those
  /// futures complete (with the original auth error) instead of hanging forever.
  void _failPending(DioException err) {
    while (_pendingRetries.isNotEmpty) {
      final pending = _pendingRetries.removeAt(0);
      pending.handler.next(
        DioException(
          requestOptions: pending.options,
          response: err.response,
          type: err.type,
          error: err.error,
        ),
      );
    }
  }

  Future<Response<dynamic>> _retry(RequestOptions original, String token) {
    return _dio.request<dynamic>(
      original.path,
      data: original.data,
      queryParameters: original.queryParameters,
      options: Options(
        method: original.method,
        headers: {...original.headers, 'Authorization': 'Bearer $token'},
        contentType: original.contentType,
        responseType: original.responseType,
      ),
    );
  }
}
