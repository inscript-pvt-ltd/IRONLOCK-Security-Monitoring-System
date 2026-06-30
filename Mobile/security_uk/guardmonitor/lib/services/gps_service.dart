import 'dart:async';
import 'dart:io' show Platform;
import 'package:battery_plus/battery_plus.dart';
import 'package:dio/dio.dart';
import 'package:drift/drift.dart' show Value;
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import '../config/api_config.dart';
import '../data/offline_queue_db.dart';
import 'api_client.dart';

/// Streams the guard's location to the backend for the whole shift — including
/// while the screen is locked or the app is backgrounded.
///
/// On Android this runs through geolocator's **foreground service** (an ongoing
/// notification keeps the process alive); on iOS through **background location
/// updates** (`UIBackgroundModes: location` + `allowBackgroundLocationUpdates`).
/// A plain `Timer` would be suspended the moment the app leaves the foreground,
/// so location is driven off a position **stream** instead — the OS wakes us to
/// deliver fixes even when backgrounded.
/// Android emits a fix on a time interval (~15s) even while the guard is
/// stationary; iOS Core Location is *distance-driven* and stays silent until
/// the device moves `distanceFilter` metres. For a static post that means iOS
/// barely updates. This heartbeat forces a one-shot fix on iOS at the same
/// cadence so a stationary guard reports like on Android. Foreground only —
/// `Timer`s are suspended when the app is backgrounded (background stays
/// movement-driven, an iOS limitation), and the position *stream* still covers
/// movement on both platforms.
const Duration _kIosHeartbeat = Duration(seconds: 15);

class GpsService {
  GpsService(this._dio, this._queueDb);
  final Dio _dio;
  // Encrypted offline queue: a ping that can't reach the server is buffered here
  // and flushed as a batch on reconnect (Phase 7). Nullable so GPS still runs if
  // the queue is unavailable.
  final OfflineQueueDb? _queueDb;
  final Battery _battery = Battery();

  StreamSubscription<Position>? _sub;
  Timer? _heartbeat;
  String? _shiftId;

  /// Starts background-capable location streaming for [shiftId]. Returns `false`
  /// when location permission is denied (so the caller can surface a warning)
  /// and `true` when the stream is running.
  Future<bool> startCapture(String shiftId) async {
    _shiftId = shiftId;

    // Confirm we have at least foreground permission before starting. Background
    // ("Always") permission is requested at shift start; if the guard granted
    // only "While Using", tracking still works while the app is open.
    final permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied ||
        permission == LocationPermission.deniedForever) {
      return false;
    }

    await _sub?.cancel();
    _sub = Geolocator.getPositionStream(locationSettings: _backgroundSettings())
        .listen(_onPosition, onError: (_) {
      // Transient stream errors (brief signal loss) — keep the subscription;
      // the next fix resumes pings.
    });

    // Post an immediate first fix so the zone card isn't blank while the stream
    // spins up (the stream's first emission can lag a few seconds).
    unawaited(_captureOnce());

    // iOS-only time-based heartbeat (see _kIosHeartbeat) so a stationary guard
    // keeps reporting at Android's cadence instead of waiting to move 10m.
    // Android already updates on a timer via AndroidSettings.intervalDuration,
    // so adding a heartbeat there would just double the pings.
    if (Platform.isIOS) {
      _heartbeat?.cancel();
      _heartbeat = Timer.periodic(_kIosHeartbeat, (_) => unawaited(_captureOnce()));
    }
    return true;
  }

  void stopCapture() {
    _sub?.cancel();
    _sub = null;
    _heartbeat?.cancel();
    _heartbeat = null;
    _shiftId = null;
  }

  /// Platform settings that keep location alive in the background.
  LocationSettings _backgroundSettings() {
    const accuracy = LocationAccuracy.high;
    if (Platform.isAndroid) {
      return AndroidSettings(
        accuracy: accuracy,
        // Desired ~15s cadence; distanceFilter 0 so fixes still arrive while the
        // guard is stationary (a static post is the common case).
        intervalDuration: const Duration(seconds: 15),
        // The foreground service is what survives a locked screen / backgrounded
        // app. The ongoing notification is OS-mandated and doubles as honest
        // disclosure to the guard that tracking is on.
        foregroundNotificationConfig: const ForegroundNotificationConfig(
          notificationTitle: 'Shift tracking active',
          notificationText:
              'IronLock is recording your patrol location during your shift.',
          enableWakeLock: true,
          setOngoing: true,
        ),
      );
    }
    if (Platform.isIOS) {
      return AppleSettings(
        accuracy: accuracy,
        activityType: ActivityType.otherNavigation,
        distanceFilter: 10,
        pauseLocationUpdatesAutomatically: false,
        // Blue background-location indicator + permission to update in the
        // background (requires UIBackgroundModes:location + Always auth).
        showBackgroundLocationIndicator: true,
        allowBackgroundLocationUpdates: true,
      );
    }
    return const LocationSettings(accuracy: accuracy);
  }

  void _onPosition(Position position) => unawaited(_postPing(position));

  /// One-shot fix used to seed the first ping immediately on start.
  Future<void> _captureOnce() async {
    try {
      final position = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 10),
        ),
      );
      await _postPing(position);
    } catch (_) {
      // iOS simulator / no fix yet — the stream will deliver when available.
    }
  }

  Future<void> _postPing(Position position) async {
    final shiftId = _shiftId;
    if (shiftId == null) return;

    // Real device battery as a 0–1 fraction; null when unknown (e.g. the iOS
    // simulator reports a negative level). Captured once so the live POST and a
    // queued copy agree.
    final battery = await _readBatteryFraction();
    final recordedAt = DateTime.now().toUtc().toIso8601String();

    try {
      final response = await _dio.post<Map<String, dynamic>>(
        ApiConfig.shiftLocations(shiftId),
        data: {
          'pings': [
            {
              'latitude': position.latitude,
              'longitude': position.longitude,
              'accuracy': position.accuracy,
              'battery': battery,
              'recorded_at': recordedAt,
            }
          ]
        },
      );

      final data = response.data?['data'] as Map<String, dynamic>?;
      final results = data?['results'] as List<dynamic>?;
      final zone = results != null && results.isNotEmpty
          ? (results.last as Map<String, dynamic>)['zone_status'] as String?
          : null;
      if (zone != null) _zoneController.add(zone);
    } catch (_) {
      // Offline / server unreachable: buffer the ping in the encrypted queue so
      // it flushes as a batch on reconnect (Phase 7). Best-effort — never throw.
      await _enqueuePing(shiftId, position, battery, recordedAt);
    }
  }

  /// Persists a ping the live POST couldn't deliver. Swallows its own errors —
  /// a failed queue write must not break location tracking.
  Future<void> _enqueuePing(
    String shiftId,
    Position position,
    double? battery,
    String recordedAt,
  ) async {
    final db = _queueDb;
    if (db == null) return;
    try {
      await db.enqueueGps(GpsQueueCompanion.insert(
        shiftId: shiftId,
        latitude: position.latitude,
        longitude: position.longitude,
        accuracy: Value(position.accuracy),
        battery: Value(battery),
        recordedAt: recordedAt,
        createdAt: DateTime.now().millisecondsSinceEpoch,
      ));
    } catch (_) {
      // Queue unavailable — drop; GPS is best-effort and the server alarms only
      // on conditions still true on reconnect anyway.
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
  final service =
      GpsService(ref.read(dioProvider), ref.read(offlineQueueDbProvider));
  ref.onDispose(service.dispose);
  return service;
});
