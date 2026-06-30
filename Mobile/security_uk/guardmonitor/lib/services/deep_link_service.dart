import 'dart:async';
import 'package:app_links/app_links.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/shift_access_provider.dart';
import 'shift_access_link.dart';

/// Listens for incoming deep links and drives the Shift Access Link (SSO) redeem.
/// Handles both delivery paths:
/// - **cold start** (app launched by the link) via `getInitialLink()`
/// - **warm** (link tapped while the app is open) via `uriLinkStream`
///
/// Only links that parse as a valid shift-access token are acted on — anything
/// else is ignored, so an unrelated `ironlock://` URL can't trigger a sign-in.
class DeepLinkService {
  DeepLinkService(this._ref);

  final Ref _ref;
  final AppLinks _appLinks = AppLinks();
  StreamSubscription<Uri>? _sub;

  Future<void> start() async {
    // Cold-start link (the app was launched by tapping the link).
    try {
      final initial = await _appLinks.getInitialLink();
      if (initial != null) _handle(initial);
    } catch (_) {
      // No initial link / platform unsupported — fine.
    }
    // Warm links while the app is already running.
    _sub = _appLinks.uriLinkStream.listen(_handle, onError: (_) {});
  }

  void _handle(Uri uri) {
    final token = extractShiftAccessToken(uri);
    if (token == null) {
      if (kDebugMode) debugPrint('[deeplink] ignored non-shift-access uri: $uri');
      return;
    }
    if (kDebugMode) debugPrint('[deeplink] shift-access token received, redeeming');
    _ref.read(shiftAccessProvider.notifier).redeem(token);
  }

  void dispose() => _sub?.cancel();
}

final deepLinkServiceProvider = Provider<DeepLinkService>((ref) {
  final service = DeepLinkService(ref);
  ref.onDispose(service.dispose);
  return service;
});
