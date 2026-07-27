import 'package:dio/dio.dart';
import '../models/api_response.dart';
import '../utils/server_time.dart';

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
        // Same payload as login. Build the copy from the machine-readable UTC
        // `details` timestamps, localized to the device — NOT the server's
        // `message` string, whose wall-clock time renders in one fixed server
        // zone and can be hours off for the guard (backend note 2026-07-23).
        return ShiftAccessException(
            'LOGIN_WINDOW_CLOSED', loginWindowMessage(err),
            windowExpired: reason == 'expired');
      // SHIFT_ACCESS_UNAUTHORIZED + anything else → show the server message.
      default:
        return ShiftAccessException(err.code, err.message);
    }
  }

  /// Guard-facing, **device-local** copy for a `LOGIN_WINDOW_CLOSED` (403).
  ///
  /// The backend sends every datetime as UTC (ISO-8601, trailing `Z`) and its
  /// `message` bakes in a wall-clock time rendered in a single fixed server zone,
  /// so it can be wrong for a guard in another timezone. The machine-readable
  /// `details` timestamps are always correct UTC — we localize those ourselves
  /// (`DateTime.parse(...).toLocal()`) and only fall back to the server `message`
  /// when `details` is absent/unparseable. Shared by the password-login and the
  /// SSO-redeem paths so both localize identically.
  static String loginWindowMessage(ApiError err) {
    final details = err.details;
    final reason = details?['reason'] as String?;
    switch (reason) {
      case 'too_early':
        final opens = _localHHmm(details?['window_opens_at']);
        final start = _localHHmm(details?['next_shift_start']);
        if (opens != null && start != null) {
          return 'You can sign in from $opens — '
              '15 minutes before your $start shift.';
        }
        return err.message; // details missing/odd → server fallback
      case 'expired':
        // The secondary "contact your supervisor" cue is shown separately by the
        // login screen when windowExpired is set, so keep this line short.
        return 'Your sign-in window has closed.';
      case 'no_shift':
        return "You don't have a shift scheduled right now.";
      default:
        return err.message;
    }
  }

  /// Parses a UTC ISO-8601 string and formats it as device-local `HH:mm`, or null
  /// when absent/unparseable. `DateTime.parse` on a `Z`-suffixed string yields a
  /// UTC instant; `.toLocal()` moves it to the device zone before we read the
  /// wall-clock fields.
  static String? _localHHmm(dynamic raw) {
    if (raw is! String) return null;
    // parseServerUtc reads a zone-less value as UTC (not device-local) before we
    // localise, so the displayed HH:mm can't drift by the device offset (M5).
    final dt = parseServerUtc(raw);
    if (dt == null) return null;
    final local = dt.toLocal();
    return '${local.hour.toString().padLeft(2, '0')}:'
        '${local.minute.toString().padLeft(2, '0')}';
  }
}
