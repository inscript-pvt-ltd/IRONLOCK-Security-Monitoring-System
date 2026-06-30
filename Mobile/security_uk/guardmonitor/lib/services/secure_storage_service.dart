import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class SecureStorageService {
  static const _storage = FlutterSecureStorage(
    aOptions: AndroidOptions(encryptedSharedPreferences: true),
    iOptions: IOSOptions(accessibility: KeychainAccessibility.first_unlock),
  );

  static const _tokenKey = 'ironlock_auth_token';
  static const _refreshKey = 'ironlock_refresh_token';
  static const _emailKey = 'ironlock_guard_email';
  static const _expiresAtKey = 'ironlock_token_expires_at';
  static const _deviceIdKey = 'ironlock_device_id';
  static const _privacyAcceptedKey = 'ironlock_privacy_accepted';
  static const _hmacSecretKey = 'ironlock_hmac_secret';
  static const _wakefulnessKey = 'ironlock_wakefulness';
  static const _photoReceiptKey = 'ironlock_photo_receipt';
  static const _seenReviewsKey = 'ironlock_seen_reviews';

  static Future<void> saveToken(String token) =>
      _storage.write(key: _tokenKey, value: token);

  static Future<String?> getToken() => _storage.read(key: _tokenKey);

  static Future<void> deleteToken() => _storage.delete(key: _tokenKey);

  static Future<void> saveRefreshToken(String token) =>
      _storage.write(key: _refreshKey, value: token);

  static Future<String?> getRefreshToken() => _storage.read(key: _refreshKey);

  static Future<void> saveEmail(String email) =>
      _storage.write(key: _emailKey, value: email);

  static Future<String?> getSavedEmail() => _storage.read(key: _emailKey);

  static Future<void> saveExpiresAt(DateTime expiresAt) =>
      _storage.write(key: _expiresAtKey, value: expiresAt.toIso8601String());

  static Future<DateTime?> getExpiresAt() async {
    final raw = await _storage.read(key: _expiresAtKey);
    return raw != null ? DateTime.tryParse(raw) : null;
  }

  static Future<String?> getDeviceId() => _storage.read(key: _deviceIdKey);

  static Future<void> saveDeviceId(String deviceId) =>
      _storage.write(key: _deviceIdKey, value: deviceId);

  /// Per-guard photo-signing key from the login response. Wiped on sign-out.
  static Future<void> saveHmacSecret(String secret) =>
      _storage.write(key: _hmacSecretKey, value: secret);

  static Future<String?> getHmacSecret() => _storage.read(key: _hmacSecretKey);

  /// Wakefulness TOTP provisioning (seed + config + schedule) issued by
  /// `POST /shifts/{id}/start`, stored as a JSON blob. Wiped on sign-out.
  static Future<void> saveWakefulness(String json) =>
      _storage.write(key: _wakefulnessKey, value: json);

  static Future<String?> getWakefulness() => _storage.read(key: _wakefulnessKey);

  static Future<void> clearWakefulness() =>
      _storage.delete(key: _wakefulnessKey);

  /// Arrival time of the most recent online photo request, stamped the instant
  /// the app first sees it (the FCM background isolate when locked, or the
  /// foreground push/poll). Lets `PhotoScreen` anchor its response countdown to
  /// when the request *arrived* rather than when the guard opens the camera —
  /// the server's photo window is fixed, so a late tap must spend the elapsed
  /// time. Single slot (the server raises one request at a time); stored as
  /// `requestId|iso8601`. Superseded by a server `issued_at` when one is sent.
  static Future<void> savePhotoReceipt(
    String requestId,
    DateTime receivedAt,
  ) =>
      _storage.write(
        key: _photoReceiptKey,
        value: '$requestId|${receivedAt.toUtc().toIso8601String()}',
      );

  /// Returns the stamped arrival for [requestId], or null if none is stored for
  /// it (e.g. the request came in while the app was foregrounded, or this is a
  /// different request).
  static Future<DateTime?> getPhotoReceipt(String requestId) async {
    final raw = await _storage.read(key: _photoReceiptKey);
    if (raw == null) return null;
    final sep = raw.indexOf('|');
    if (sep <= 0) return null;
    if (raw.substring(0, sep) != requestId) return null;
    return DateTime.tryParse(raw.substring(sep + 1));
  }

  static Future<void> clearPhotoReceipt() =>
      _storage.delete(key: _photoReceiptKey);

  /// Request-ids of photo reviews already surfaced to the guard, so a review is
  /// notified exactly once across the push + poll paths and across relaunches.
  /// Capped (newest-kept) so it can't grow without bound over a long install.
  static const int _maxSeenReviews = 80;

  static Future<List<String>> getSeenReviewIds() async {
    final raw = await _storage.read(key: _seenReviewsKey);
    if (raw == null || raw.isEmpty) return const [];
    try {
      return (jsonDecode(raw) as List).cast<String>();
    } catch (_) {
      return const [];
    }
  }

  static Future<void> addSeenReviewId(String requestId) async {
    final current = await getSeenReviewIds();
    if (current.contains(requestId)) return;
    final next = [...current, requestId];
    // Keep the most recent ids if we exceed the cap.
    final capped = next.length > _maxSeenReviews
        ? next.sublist(next.length - _maxSeenReviews)
        : next;
    await _storage.write(key: _seenReviewsKey, value: jsonEncode(capped));
  }

  /// Privacy-notice acceptance. Persisted per install (survives sign-out, like
  /// `device_id`) so the guard isn't re-prompted on every session.
  static Future<bool> getPrivacyAccepted() async =>
      (await _storage.read(key: _privacyAcceptedKey)) == 'true';

  static Future<void> setPrivacyAccepted() =>
      _storage.write(key: _privacyAcceptedKey, value: 'true');

  /// Wipes the signed-in session (tokens, email, photo-signing key, wakefulness
  /// seed) but keeps the per-install `device_id` stable, as required by the
  /// device-info contract.
  static Future<void> clearSession() => Future.wait([
        _storage.delete(key: _tokenKey),
        _storage.delete(key: _refreshKey),
        _storage.delete(key: _emailKey),
        _storage.delete(key: _expiresAtKey),
        _storage.delete(key: _hmacSecretKey),
        _storage.delete(key: _wakefulnessKey),
        _storage.delete(key: _photoReceiptKey),
        _storage.delete(key: _seenReviewsKey),
      ]);
}
