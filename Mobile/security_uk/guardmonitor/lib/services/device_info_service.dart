import 'dart:io';
import 'dart:math';
import 'secure_storage_service.dart';

/// Produces the `device` object sent on login/refresh (§4.4 of the API
/// contract) without pulling in a device-info package: `device_id` is a
/// random hex string generated once and persisted for the life of the install.
abstract class DeviceInfoService {
  static const String deviceName = 'Ironlock Guard App';
  static const String appVersion = '4.0.0';

  static String get platform {
    if (Platform.isAndroid) return 'android';
    if (Platform.isIOS) return 'ios';
    return 'unknown';
  }

  static Future<String> getOrCreateDeviceId() async {
    final existing = await SecureStorageService.getDeviceId();
    if (existing != null) return existing;
    final generated = _randomHex(16);
    await SecureStorageService.saveDeviceId(generated);
    return generated;
  }

  static Future<Map<String, dynamic>> toJson() async {
    return {
      'device_id': await getOrCreateDeviceId(),
      'device_name': deviceName,
      'platform': platform,
      'app_version': appVersion,
    };
  }

  static String _randomHex(int byteLength) {
    final random = Random.secure();
    final bytes = List<int>.generate(byteLength, (_) => random.nextInt(256));
    return bytes.map((b) => b.toRadixString(16).padLeft(2, '0')).join();
  }
}
