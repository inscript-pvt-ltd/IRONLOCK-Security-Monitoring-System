import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/providers/photo_provider.dart';
import 'package:guardmonitor/providers/photo_schedule_provider.dart';
import 'package:guardmonitor/services/time_anchor_service.dart';

/// A mid-shift app-kill used to drop the in-memory photo `_fired` set, so a
/// cold-start restore would re-fire a scheduled photo capture "out of nowhere"
/// (re-opening the camera for a mark the guard already handled). The set is now
/// persisted; these tests simulate a kill (fresh container, shared storage).
class _FixedTimeAnchor extends TimeAnchorService {
  _FixedTimeAnchor(this._now);
  final DateTime _now;
  @override
  DateTime trustedNow() => _now;
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  final store = <String, String>{};

  setUp(() {
    store.clear();
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
      (call) async {
        final args = (call.arguments as Map?) ?? const {};
        final key = args['key'] as String?;
        switch (call.method) {
          case 'write':
            if (key != null) store[key] = args['value'] as String;
            return null;
          case 'read':
            return key == null ? null : store[key];
          case 'delete':
            if (key != null) store.remove(key);
            return null;
          case 'readAll':
            return Map<String, String>.from(store);
          case 'deleteAll':
            store.clear();
            return null;
          default:
            return null;
        }
      },
    );
  });

  tearDown(() {
    TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
        .setMockMethodCallHandler(
      const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
      null,
    );
  });

  // A schedule with one mark due at 12:00; "now" is 12:05 (inside the 15-min
  // fire window). trustedNow is pinned so the test is clock-independent.
  ProviderContainer containerAt(String now) => ProviderContainer(overrides: [
        timeAnchorServiceProvider.overrideWithValue(_FixedTimeAnchor(
          DateTime.parse(now).toUtc(),
        )),
      ]);

  Future<void> provision(ProviderContainer c) =>
      c.read(photoScheduleProvider.notifier).provisionFromJson({
        'schedule': ['2026-06-30T12:00:00Z'],
        'offline_nonce_ttl_minutes': 15,
      });

  test('a fired scheduled photo is NOT re-fired after an app-kill + restore',
      () async {
    // First run: the due mark fires an offline capture.
    final c1 = containerAt('2026-06-30T12:05:00Z');
    await provision(c1);
    c1.read(photoScheduleProvider.notifier).checkSchedule(offline: true);
    expect(c1.read(pendingPhotoProvider).pending, isTrue,
        reason: 'the due mark should fire the first time');
    await Future<void>.delayed(Duration.zero); // let the persist land
    c1.dispose();

    // App-kill → cold start: a fresh container sharing the same storage.
    final c2 = containerAt('2026-06-30T12:06:00Z');
    await c2.read(photoScheduleProvider.notifier).restore();
    expect(c2.read(photoScheduleProvider.notifier).isArmed, isTrue);
    c2.read(photoScheduleProvider.notifier).checkSchedule(offline: true);
    expect(c2.read(pendingPhotoProvider).pending, isFalse,
        reason: 'the already-fired mark must NOT re-fire after restore');
    c2.dispose();
  });

  test('clear() wipes the persisted fired set', () async {
    final c = containerAt('2026-06-30T12:05:00Z');
    await provision(c);
    c.read(photoScheduleProvider.notifier).checkSchedule(offline: true);
    await Future<void>.delayed(Duration.zero);
    expect(store.containsKey('ironlock_photo_fired'), isTrue);

    await c.read(photoScheduleProvider.notifier).clear();
    expect(store.containsKey('ironlock_photo_fired'), isFalse);
    c.dispose();
  });
}
