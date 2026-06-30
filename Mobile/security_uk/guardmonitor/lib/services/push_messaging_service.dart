import 'package:dio/dio.dart';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../providers/app_providers.dart';
import '../providers/wakefulness_provider.dart';
import 'push_router.dart';
import 'push_service.dart';
import 'secure_storage_service.dart';
import 'wakefulness_service.dart';

/// FCM glue. Keeps Firebase concerns in one place and forwards every push to
/// the pure [routePush] dispatcher, which drives the existing wakefulness/photo
/// providers — so the overlay + photo navigation react exactly as they do for
/// the polling path.
///
/// Resilient by design: if Firebase isn't configured for the running platform
/// (e.g. iOS before its GoogleService-Info.plist is added), [init] swallows the
/// error and FCM simply stays off — the polling fallback keeps working.
class PushMessaging {
  PushMessaging._();

  static bool _available = false;
  static bool _delivering = false;
  static bool _listenersWired = false;

  /// Whether Firebase initialised for this platform. When false (e.g. iOS before
  /// its plist is added) push is off and the local TOTP scheduler / polling is
  /// the delivery path, so callers can decide whether to drive those fallbacks.
  static bool get isAvailable => _available;

  /// Whether push can **actually deliver** — i.e. we obtained and registered an
  /// FCM token. This is stricter than [isAvailable]: on iOS, Firebase core can
  /// initialise from the plist (`isAvailable == true`) yet `getToken()` still
  /// fails because there's no APNs token (no Push capability / `.p8` key), so no
  /// challenge can ever arrive. Callers gate the local TOTP scheduler on this so
  /// challenges aren't silently dropped while we wait for a push that can't come.
  static bool get isDelivering => _delivering;

  /// Call once in `main()` after `WidgetsFlutterBinding.ensureInitialized()`.
  static Future<void> init() async {
    try {
      await Firebase.initializeApp();
      FirebaseMessaging.onBackgroundMessage(_backgroundHandler);
      _available = true;
    } catch (e) {
      // Not configured for this platform yet (e.g. iOS plist missing) — leave
      // push off; polling remains the delivery path.
      if (kDebugMode) debugPrint('[fcm] init skipped: $e');
    }
  }

  /// Call whenever the guard signs in (so the token registers against the
  /// current authenticated session — important if a different guard signs in on
  /// the same device). Safe to call repeatedly: the token is re-registered each
  /// time, but the message listeners are wired only once. [ref] must be stable
  /// for the app's lifetime (it's held by the long-lived listeners).
  static Future<void> start(WidgetRef ref) async {
    if (!_available) return;
    final messaging = FirebaseMessaging.instance;
    try {
      await messaging.requestPermission();

      // On iOS this throws `apns-token-not-set` when there's no APNs key/Push
      // capability — so _delivering stays false and the local scheduler keeps
      // running instead of waiting for a push that can never arrive.
      final token = await messaging.getToken();
      if (token != null) {
        await ref.read(pushServiceProvider).registerToken(token);
        _delivering = true;
      }

      if (!_listenersWired) {
        _listenersWired = true;
        messaging.onTokenRefresh.listen((t) {
          ref.read(pushServiceProvider).registerToken(t);
          // A token arriving later (e.g. APNs comes good) means push can now
          // deliver — flip the scheduler off and let push be the authority.
          _delivering = true;
        });
        // Foreground delivery: no background handler ran, so we send /received
        // here. Tap paths (opened-app / cold-start) mean the message was already
        // background-delivered — the background isolate sent /received then, so
        // we must NOT send it again (H4).
        FirebaseMessaging.onMessage
            .listen((m) => _dispatch(ref, m.data, confirmReceipt: true));
        FirebaseMessaging.onMessageOpenedApp
            .listen((m) => _dispatch(ref, m.data, confirmReceipt: false));
        final initial = await messaging.getInitialMessage();
        if (initial != null) {
          _dispatch(ref, initial.data, confirmReceipt: false);
        }
      }
    } catch (e) {
      if (kDebugMode) debugPrint('[fcm] start failed: $e');
    }
  }

  /// Drops the local FCM token (best-effort) so the next guard to sign in on
  /// this device gets a fresh one and isn't aliased to the previous session.
  static Future<void> clearToken() async {
    if (!_available) return;
    _delivering = false;
    try {
      await FirebaseMessaging.instance.deleteToken();
    } catch (e) {
      if (kDebugMode) debugPrint('[fcm] deleteToken failed: $e');
    }
  }

  static void _dispatch(
    WidgetRef ref,
    Map<String, dynamic> data, {
    required bool confirmReceipt,
  }) {
    if (kDebugMode) debugPrint('[fcm] message: $data');
    routePush(
      data,
      onConfirmReceived: (checkId) {
        if (confirmReceipt) {
          ref.read(wakefulnessServiceProvider).confirmReceived(checkId);
        }
      },
      onWakefulness: (checkId, code, responseSeconds, issuedAt) {
        // Don't stomp a challenge already on screen; the notifier also dedupes
        // by check_id so a push can't re-raise one the scheduler/an earlier push
        // already handled.
        if (ref.read(wakefulnessProvider).status == WakefulnessStatus.idle) {
          ref.read(wakefulnessProvider.notifier).trigger(
                checkId,
                code,
                responseSeconds: responseSeconds ?? 60,
                issuedAt: issuedAt,
              );
        }
      },
      onPhoto: (requestId, nonceValue, issuedAt, responseSeconds) =>
          ref.read(pendingPhotoProvider.notifier).setPending(
                true,
                requestId: requestId,
                nonceValue: nonceValue,
                issuedAt: issuedAt,
                // Foreground delivery — the app sees it now, so anchor the
                // countdown here when the server didn't send an issue time.
                receivedAt: DateTime.now().toUtc(),
                responseSeconds: responseSeconds,
              ),
      onPhotoReviewed: (requestId, shiftId, decision, note) =>
          ref.read(photoReviewProvider.notifier).ingestPush(
                requestId: requestId,
                decision: decision,
                note: note,
              ),
    );
  }
}

/// Runs in a separate isolate when a push lands while the app is backgrounded or
/// terminated. The OS draws the `notification` block automatically; here we do
/// the type-specific bookkeeping that must happen at *arrival* (not when the
/// guard later taps), using direct storage/Dio since the app's providers aren't
/// available in this isolate.
@pragma('vm:entry-point')
Future<void> _backgroundHandler(RemoteMessage message) async {
  await Firebase.initializeApp();
  final data = message.data;
  switch (data['type']) {
    case 'WAKEFULNESS_CHALLENGE':
      await _confirmWakefulnessReceipt(data);
    case 'PHOTO_REQUEST':
      // Stamp the arrival time now so the capture screen can anchor its 90s
      // window to when the request landed, even if the guard taps the
      // notification minutes later. Superseded by a server `issued_at` if sent.
      final requestId = data['request_id']?.toString();
      if (requestId != null && requestId.isNotEmpty) {
        await SecureStorageService.savePhotoReceipt(
          requestId,
          DateTime.now().toUtc(),
        );
      }
  }
}

/// Fire the wakefulness `/received` receipt ASAP (the whole point of it), using
/// a standalone Dio since the app's providers aren't available off the UI
/// isolate.
Future<void> _confirmWakefulnessReceipt(Map<String, dynamic> data) async {
  final checkId = data['check_id']?.toString();
  if (checkId == null || checkId.isEmpty) return;
  try {
    final token = await SecureStorageService.getToken();
    if (token == null) return;
    final dio = Dio(BaseOptions(
      baseUrl: ApiConfig.baseUrl,
      headers: {'Authorization': 'Bearer $token'},
    ));
    await dio.post<void>(ApiConfig.wakefulnessReceived(checkId));
  } catch (_) {
    // Best-effort — a missed receipt only risks a false alarm, not safety.
  }
}
