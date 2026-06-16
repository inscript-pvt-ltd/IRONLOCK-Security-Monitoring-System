import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import 'device_info_service.dart';
import 'secure_storage_service.dart';

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

  return dio;
});

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

      // Retry the original request with the new token
      final retried = await _retry(err.requestOptions, newToken);
      handler.resolve(retried);

      // Drain any requests that queued while we were refreshing
      for (final pending in _pendingRetries) {
        try {
          final r = await _retry(pending.options, newToken);
          pending.handler.resolve(r);
        } catch (e) {
          pending.handler.next(err);
        }
      }
    } catch (_) {
      // Refresh itself failed (e.g. TOKEN_INVALID/TOKEN_EXPIRED on refresh) —
      // terminal: there's no token to recover with, force the user to Login.
      handler.next(err);
      _ref.read(forcedSignOutCallbackProvider)?.call();
    } finally {
      _pendingRetries.clear();
      _refreshing = false;
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
