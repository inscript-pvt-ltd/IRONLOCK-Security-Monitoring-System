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

  /// Wipes the signed-in session (tokens, email) but keeps the per-install
  /// `device_id` stable, as required by the device-info contract.
  static Future<void> clearSession() => Future.wait([
        _storage.delete(key: _tokenKey),
        _storage.delete(key: _refreshKey),
        _storage.delete(key: _emailKey),
        _storage.delete(key: _expiresAtKey),
      ]);
}
