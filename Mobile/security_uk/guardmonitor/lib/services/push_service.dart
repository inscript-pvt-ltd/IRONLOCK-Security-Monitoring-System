import 'dart:io' show Platform;
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import 'api_client.dart';

/// Registers the device's push token so the server can wake the app for
/// welfare/photo checks (`POST /devices/push-token`).
///
/// NOTE: acquiring the actual FCM/APNs token requires a Firebase project +
/// native config (GoogleService-Info.plist / google-services.json / APNs key),
/// which is the remaining external dependency. Once `firebase_messaging`
/// yields a token, call [registerToken] with it — typically right after sign-in
/// and on every token refresh. Until then the pending polls are the fallback,
/// exactly as the contract intends.
class PushService {
  const PushService(this._dio);
  final Dio _dio;

  /// Best-effort: the server still creates checks if push fails, so a failed
  /// registration must never block the app.
  Future<void> registerToken(String pushToken) async {
    try {
      await _dio.post<void>(
        ApiConfig.pushToken,
        data: {
          'push_token': pushToken,
          'platform': Platform.isIOS ? 'ios' : 'android',
        },
      );
    } catch (_) {
      // Polling remains the reliable delivery path.
    }
  }

  /// Unmaps this device's push token on sign-out so a signed-out guard stops
  /// receiving pushes on this device before the next guard signs in. Best-effort
  /// and must run while the token is still valid (i.e. before clearSession).
  Future<void> unregisterToken() async {
    try {
      await _dio.delete<void>(ApiConfig.pushToken);
    } catch (_) {
      // Nothing to do — the next sign-in re-registers and overwrites the map.
    }
  }
}

final pushServiceProvider = Provider<PushService>(
  (ref) => PushService(ref.read(dioProvider)),
);
