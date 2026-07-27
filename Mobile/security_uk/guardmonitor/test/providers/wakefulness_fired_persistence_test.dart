import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/providers/wakefulness_provider.dart';

/// A mid-shift app-kill used to drop the in-memory `_fired` set, so a cold-start
/// restore would re-challenge — and double-count — a welfare mark the guard had
/// already answered. The set is now persisted; these tests lock that in by
/// simulating a kill (a fresh ProviderContainer sharing the same storage).
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  // A STATEFUL secure-storage stub (the real plugin returns null-for-all, which
  // would hide the round-trip). Backs read/write/delete with an in-memory map.
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

  // A mark that's due right now and comfortably inside its response window.
  Map<String, dynamic> provisioningJson() => {
        'totp_seed': 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ',
        'totp_period_seconds': 30,
        'totp_digits': 4,
        'response_seconds': 60,
        'schedule': [DateTime.now().toUtc().toIso8601String()],
      };

  test('a fired mark is NOT re-raised after an app-kill + restore', () async {
    // First run: provision, then the due mark fires a challenge.
    final c1 = ProviderContainer();
    await c1.read(wakefulnessScheduleProvider.notifier).provisionFromJson(
          provisioningJson(),
        );
    c1.read(wakefulnessScheduleProvider.notifier).checkSchedule();
    expect(c1.read(wakefulnessProvider).status, WakefulnessStatus.challenge,
        reason: 'the due mark should raise a challenge the first time');
    // Give the fire-and-forget persist a turn to hit the (stubbed) storage.
    await Future<void>.delayed(Duration.zero);
    c1.dispose();

    // App-kill → cold start: a brand-new container sharing the same storage.
    final c2 = ProviderContainer();
    await c2.read(wakefulnessScheduleProvider.notifier).restore();
    expect(c2.read(wakefulnessScheduleProvider.notifier).isArmed, isTrue,
        reason: 'restore should re-arm the schedule from storage');
    c2.read(wakefulnessScheduleProvider.notifier).checkSchedule();
    expect(c2.read(wakefulnessProvider).status, WakefulnessStatus.idle,
        reason: 'the already-fired mark must NOT re-raise after restore');
    c2.dispose();
  });

  test('clear() wipes the persisted fired set (fresh shift starts clean)',
      () async {
    final c = ProviderContainer();
    await c.read(wakefulnessScheduleProvider.notifier).provisionFromJson(
          provisioningJson(),
        );
    c.read(wakefulnessScheduleProvider.notifier).checkSchedule();
    await Future<void>.delayed(Duration.zero);
    expect(store.containsKey('ironlock_wakefulness_fired'), isTrue);

    await c.read(wakefulnessScheduleProvider.notifier).clear();
    expect(store.containsKey('ironlock_wakefulness_fired'), isFalse);
    c.dispose();
  });
}
