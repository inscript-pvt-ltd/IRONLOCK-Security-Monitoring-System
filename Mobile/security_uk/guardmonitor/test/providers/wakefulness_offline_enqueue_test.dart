import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/data/offline_queue_db.dart';
import 'package:guardmonitor/providers/wakefulness_provider.dart';
import 'package:guardmonitor/services/wakefulness_service.dart';

/// Pretends the server is unreachable, so the offline-answer queueing path runs.
class _OfflineWakefulness extends WakefulnessService {
  _OfflineWakefulness() : super(Dio());
  @override
  Future<bool> respond(String checkId, String code,
      {int? windowReference, bool isOffline = false}) async {
    throw DioException(
      requestOptions: RequestOptions(path: '/x'),
      type: DioExceptionType.connectionTimeout,
    );
  }
}

void main() {
  late OfflineQueueDb db;
  setUp(() => db = OfflineQueueDb.forTesting(NativeDatabase.memory()));
  tearDown(() => db.close());

  ProviderContainer container() => ProviderContainer(overrides: [
        wakefulnessServiceProvider.overrideWithValue(_OfflineWakefulness()),
        offlineQueueDbProvider.overrideWithValue(db),
      ]);

  void enter(WakefulnessNotifier n, String code) {
    for (final d in code.split('')) {
      n.addDigit(d);
    }
  }

  test('offline TOTP answer that can\'t reach the server is queued for replay',
      () async {
    final c = container();
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.triggerLocal('chk-off', '1234', windowReference: 777, responseSeconds: 60);
    enter(n, '1234');
    await n.submit();

    final queued = await db.dueWakefulness(9999999999999);
    expect(queued, hasLength(1));
    expect(queued.single.checkId, 'chk-off');
    expect(queued.single.code, '1234');
    expect(queued.single.windowReference, 777,
        reason: 'the trusted proof-of-time is preserved verbatim');
    // Lenient: a guard who answered in time is still shown success offline.
    expect(c.read(wakefulnessProvider).status, WakefulnessStatus.success);
  });

  test('an ONLINE (push) challenge is NOT queued — no offline replay path',
      () async {
    final c = container();
    addTearDown(c.dispose);
    final n = c.read(wakefulnessProvider.notifier);
    n.trigger('chk-online', '1234'); // isOffline = false
    enter(n, '1234');
    await n.submit();
    expect(await db.dueWakefulness(9999999999999), isEmpty);
  });
}
