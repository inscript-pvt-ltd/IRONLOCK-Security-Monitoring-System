import 'dart:async';
import 'package:battery_plus/battery_plus.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

// Small, self-contained UI/state providers with no service dependencies.

// ── Zone ─────────────────────────────────────────────────────────────────
// 0 = inside, 1 = outside, 2 = no signal

class ZoneNotifier extends Notifier<int> {
  @override
  int build() => 2; // unknown until first real GPS fix (0 = inside, 1 = outside, 2 = no signal)

  void set(int state) => this.state = state.clamp(0, 2);
  void cycle() => state = (state + 1) % 3;
}

final zoneProvider = NotifierProvider<ZoneNotifier, int>(ZoneNotifier.new);

// ── Zone last-updated timestamp ───────────────────────────────────────────
// Records the moment the backend last returned a zone_status from a GPS ping,
// so the UI can show an honest "updated Xs ago" instead of a hardcoded value.
// Stays null until the first real fix arrives (e.g. on the iOS simulator, where
// GPS throws and no ping is ever sent).

class ZoneUpdatedAtNotifier extends Notifier<DateTime?> {
  @override
  DateTime? build() => null;

  void markNow() => state = DateTime.now();
  void reset() => state = null;
}

final zoneUpdatedAtProvider =
    NotifierProvider<ZoneUpdatedAtNotifier, DateTime?>(ZoneUpdatedAtNotifier.new);

// ── Location permission denied ─────────────────────────────────────────────
// True when GPS capture couldn't start because location permission is denied.
// Drives a persistent "Location tracking OFF" banner so a guard can't work
// untracked without knowing it. Set when a shift starts/resumes; cleared on end.

class LocationDeniedNotifier extends Notifier<bool> {
  @override
  bool build() => false;

  void set(bool denied) => state = denied;
}

final locationDeniedProvider =
    NotifierProvider<LocationDeniedNotifier, bool>(LocationDeniedNotifier.new);

// ── Battery ───────────────────────────────────────────────────────────────

// Real device battery level (0–100). `null` means unknown — e.g. the iOS
// simulator, which reports no battery — so the UI can say so honestly instead
// of inventing a percentage. Reads on build, then on a 30s poll and whenever
// the charging state changes.

class BatteryNotifier extends Notifier<double?> {
  final _battery = Battery();
  Timer? _timer;
  StreamSubscription<BatteryState>? _sub;

  @override
  double? build() {
    _refresh();
    _timer = Timer.periodic(const Duration(seconds: 30), (_) => _refresh());
    _sub = _battery.onBatteryStateChanged.listen((_) => _refresh());
    ref.onDispose(() {
      _timer?.cancel();
      _sub?.cancel();
    });
    return null; // unknown until the first real read lands
  }

  Future<void> _refresh() async {
    try {
      final level = await _battery.batteryLevel;
      // Simulators / unsupported devices return a negative "unknown" level.
      state = level < 0 ? null : level.toDouble();
    } catch (_) {
      state = null;
    }
  }
}

final batteryProvider =
    NotifierProvider<BatteryNotifier, double?>(BatteryNotifier.new);

// ── Privacy accepted ──────────────────────────────────────────────────────

class PrivacyNotifier extends Notifier<bool> {
  @override
  bool build() => false;

  void accept() => state = true;
}

final privacyAcceptedProvider =
    NotifierProvider<PrivacyNotifier, bool>(PrivacyNotifier.new);

// ── Active tab index ──────────────────────────────────────────────────────

class ActiveTabNotifier extends Notifier<int> {
  @override
  int build() => 0;

  void setTab(int index) => state = index;
}

final activeTabProvider =
    NotifierProvider<ActiveTabNotifier, int>(ActiveTabNotifier.new);
