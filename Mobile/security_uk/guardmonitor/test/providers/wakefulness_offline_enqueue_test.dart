import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/data/offline_queue_db.dart';
import 'package:guardmonitor/providers/shift_provider.dart';
import 'package:guardmonitor/providers/wakefulness_provider.dart';
import 'package:guardmonitor/services/wakefulness_service.dart';

DioException _timeout() => DioException(
      requestOptions: RequestOptions(path: '/x'),
      type: DioExceptionType.connectionTimeout,
    );

/// Server unreachable on BOTH paths — the offline replay must then be buffered.
class _OfflineWakefulness extends WakefulnessService {
  _OfflineWakefulness() : super(Dio());
  @override
  Future<bool> respond(String checkId, String code) async => throw _timeout();
  @override
  Future<void> submitOffline({
    required String shiftId,
    required String code,
    required int windowReference,
    required String respondedAt,
    String? scheduledAt,
  }) async =>
      throw _timeout();
}

/// Server reachable — records the immediate offline submission (online path).
class _OnlineOfflineFlush extends WakefulnessService {
  _OnlineOfflineFlush() : super(Dio());
  final calls = <Map<String, dynamic>>[];
  @override
  Future<bool> respond(String checkId, String code) async => true;
  @override
  Future<void> submitOffline({
    required String shiftId,
    required String code,
    required int windowReference,
    required String respondedAt,
    String? scheduledAt,
  }) async {
    calls.add({
      'shiftId': shiftId,
      'code': code,
      'window': windowReference,
      'scheduledAt': scheduledAt,
    });
  }
}

/// An active shift so the report path has a shift id to target.
class _ActiveShift extends ShiftNotifier {
  @override
  ShiftState build() => const ShiftState(active: true, id: 's1');
}

void main() {
  late OfflineQueueDb db;
  setUp(() => db = OfflineQueueDb.forTesting(NativeDatabase.memory()));
  tearDown(() => db.close());

  ProviderContainer container(WakefulnessService svc) =>
      ProviderContainer(overrides: [
        wakefulnessServiceProvider.overrideWithValue(svc),
        offlineQueueDbProvider.overrideWithValue(db),
        shiftProvider.overrideWith(_ActiveShift.new),
      ]);

  void enter(WakefulnessNotifier n, String code) {
    for (final d in code.split('')) {
      n.addDigit(d);
    }
  }

  test('offline TOTP answer that can\'t reach the server is queued for replay',
      () async {
    final c = container(_OfflineWakefulness());
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.triggerLocal('chk-off', '1234',
        windowReference: 777,
        scheduledAt: DateTime.utc(2026, 6, 30, 10),
        responseSeconds: 60);
    enter(n, '1234');
    await n.submit();

    final queued = await db.dueWakefulness(9999999999999);
    expect(queued, hasLength(1));
    expect(queued.single.shiftId, 's1');
    expect(queued.single.checkId, 'chk-off');
    expect(queued.single.code, '1234');
    expect(queued.single.windowReference, 777,
        reason: 'the trusted proof-of-time is preserved verbatim');
    expect(queued.single.scheduledAt, '2026-06-30T10:00:00.000Z');
    // Lenient: a guard who answered in time is still shown success offline.
    expect(c.read(wakefulnessProvider).status, WakefulnessStatus.success);
  });

  test('offline TOTP answer WHILE ONLINE is recorded immediately, not queued',
      () async {
    final svc = _OnlineOfflineFlush();
    final c = container(svc);
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.triggerLocal('chk-live', '1234',
        windowReference: 888,
        scheduledAt: DateTime.utc(2026, 6, 30, 11),
        responseSeconds: 60);
    enter(n, '1234');
    await n.submit();

    // Sent straight to the offline materialise endpoint — nothing buffered.
    expect(svc.calls, hasLength(1));
    expect(svc.calls.single['shiftId'], 's1');
    expect(svc.calls.single['window'], 888);
    expect(svc.calls.single['scheduledAt'], '2026-06-30T11:00:00.000Z');
    expect(await db.dueWakefulness(9999999999999), isEmpty);
  });

  test('an ONLINE (push) challenge is NOT queued — it uses /respond', () async {
    final c = container(_OfflineWakefulness());
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.trigger('chk-online', '1234'); // isOffline = false → respond path
    enter(n, '1234');
    await n.submit();
    expect(await db.dueWakefulness(9999999999999), isEmpty);
  });
}
