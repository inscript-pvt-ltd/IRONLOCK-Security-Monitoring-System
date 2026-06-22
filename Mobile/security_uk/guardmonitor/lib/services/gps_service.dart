import 'dart:async';
import 'package:battery_plus/battery_plus.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import '../config/api_config.dart';
import 'api_client.dart';

class GpsService {
  GpsService(this._dio);
  final Dio _dio;
  final Battery _battery = Battery();

  Timer? _timer;
  String? _shiftId;

  Future<void> startCapture(String shiftId) async {
    _shiftId = shiftId;

    // Confirm permission before starting the loop
    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return;
    }

    _timer?.cancel();
    _timer = Timer.periodic(const Duration(seconds: 15), (_) => _capture());
    // Capture immediately on start
    _capture();
  }

  void stopCapture() {
    _timer?.cancel();
    _timer = null;
    _shiftId = null;
  }

  Future<void> _capture() async {
    final shiftId = _shiftId;
    if (shiftId == null) return;

    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );

      final ping = {
        'latitude': position.latitude,
        'longitude': position.longitude,
        'accuracy': position.accuracy,
        // Real device battery as a 0–1 fraction; null when unknown (e.g. the
        // iOS simulator reports a negative level).
        'battery': await _readBatteryFraction(),
        'recorded_at': DateTime.now().toUtc().toIso8601String(),
      };

      final response = await _dio.post<Map<String, dynamic>>(
        ApiConfig.shiftLocations(shiftId),
        data: {'pings': [ping]},
      );

      final data = response.data?['data'] as Map<String, dynamic>?;
      final results = data?['results'] as List<dynamic>?;
      final zone = results != null && results.isNotEmpty
          ? (results.last as Map<String, dynamic>)['zone_status'] as String?
          : null;
      if (zone != null) _zoneController.add(zone);
    } catch (_) {
      // Silently ignore — offline queue phase will handle persistence
    }
  }

  /// Real battery level as a 0–1 fraction, or null when the platform can't
  /// report it (e.g. the iOS simulator returns a negative "unknown" level).
  Future<double?> _readBatteryFraction() async {
    try {
      final level = await _battery.batteryLevel;
      return level < 0 ? null : level / 100.0;
    } catch (_) {
      return null;
    }
  }

  final _zoneController = StreamController<String>.broadcast();
  Stream<String> get zoneStream => _zoneController.stream;

  void dispose() {
    stopCapture();
    _zoneController.close();
  }
}

final gpsServiceProvider = Provider<GpsService>((ref) {
  final service = GpsService(ref.read(dioProvider));
  ref.onDispose(service.dispose);
  return service;
});
