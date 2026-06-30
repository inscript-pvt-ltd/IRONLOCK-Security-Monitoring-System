import 'package:dio/dio.dart';
import '../models/api_response.dart';

/// A Shift Access Link (SSO) token is the **last path segment** of the link —
/// a 64-char hex string. We accept both the production https form and the
/// custom scheme, and reject anything that isn't a shift-access link so an
/// unrelated deep link can never trigger a redeem:
///
/// - `https://<domain>/m/shift-access/<token>`  → segments `[m, shift-access, <token>]`
/// - `ironlock://shift-access/<token>`          → host `shift-access`, segments `[<token>]`
///
/// Returns the token, or null when the URI isn't a valid shift-access link.
final _tokenPattern = RegExp(r'^[0-9a-fA-F]{64}$');

String? extractShiftAccessToken(Uri uri) {
  final segments = uri.pathSegments.where((s) => s.isNotEmpty).toList();
  // The link must actually be a shift-access link — either by custom-scheme host
  // or by carrying the `shift-access` path marker.
  final isShiftAccess =
      uri.host == 'shift-access' || segments.contains('shift-access');
  if (!isShiftAccess) return null;

  if (segments.isEmpty) return null;
  final last = segments.last;
  // Guard against the bare ".../shift-access" with no token, and validate shape.
  if (!_tokenPattern.hasMatch(last)) return null;
  return last;
}

/// A redeem failure with a code mapped to guard-facing copy. `windowExpired`
/// mirrors the login screen's "you're late → contact supervisor" styling for a
/// valid link tapped outside the shift window.
class ShiftAccessException implements Exception {
  const ShiftAccessException(this.code, this.message,
      {this.windowExpired = false});

  final String code;
  final String message;
  final bool windowExpired;

  /// Maps the documented SHIFT_ACCESS_* / LOGIN_WINDOW_CLOSED / generic codes to
  /// the copy from the API guide, falling back to the server message.
  factory ShiftAccessException.fromDio(DioException e) {
    final err = ApiError.fromDioException(e);
    final reason = err.details?['reason'] as String?;
    switch (err.code) {
      case 'SHIFT_ACCESS_INVALID':
        return const ShiftAccessException('SHIFT_ACCESS_INVALID',
            'This access link is invalid. Ask your supervisor for a new one.');
      case 'SHIFT_ACCESS_EXPIRED':
        return const ShiftAccessException('SHIFT_ACCESS_EXPIRED',
            'This link has expired. Ask your supervisor for a new one.');
      case 'SHIFT_ACCESS_USED':
        return const ShiftAccessException('SHIFT_ACCESS_USED',
            'This link has already been used. Ask your supervisor for a new one.');
      case 'SHIFT_ACCESS_SHIFT_INVALID':
        return const ShiftAccessException('SHIFT_ACCESS_SHIFT_INVALID',
            'This shift is no longer available.');
      case 'ACCOUNT_LOCKED':
        return const ShiftAccessException('ACCOUNT_LOCKED',
            'Account locked. Please contact your supervisor.');
      case 'VALIDATION_ERROR':
        return const ShiftAccessException('VALIDATION_ERROR',
            'This access link is invalid. Ask your supervisor for a new one.');
      case 'RATE_LIMITED':
        return const ShiftAccessException(
            'RATE_LIMITED', 'Please try again in a moment.');
      case 'LOGIN_WINDOW_CLOSED':
        // Same payload as login — reuse the server's localized message and flag
        // the "expired/late" case so the UI can show the contact-supervisor cue.
        return ShiftAccessException('LOGIN_WINDOW_CLOSED', err.message,
            windowExpired: reason == 'expired');
      // SHIFT_ACCESS_UNAUTHORIZED + anything else → show the server message.
      default:
        return ShiftAccessException(err.code, err.message);
    }
  }
}
