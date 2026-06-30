import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/data/offline_queue_db.dart';
import 'package:guardmonitor/services/sync_flush_service.dart';

/// Builds a Dio whose every request is short-circuited: it records the posted
/// `pings` and either resolves 200 or rejects with [failStatus]/[failCode].
({Dio dio, List<List<dynamic>> posted}) _fakeDio({
  int? failStatus,
  String? failCode,
}) {
  final posted = <List<dynamic>>[];
  final dio = Dio();
  dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
    final data = options.data;
    if (data is Map && data['pings'] is List) {
      posted.add(data['pings'] as List);
    }
    if (failStatus != null) {
      handler.reject(DioException(
        requestOptions: options,
        response: Response(
          requestOptions: options,
          statusCode: failStatus,
          data: failCode == null
              ? null
              : {
                  'error': {'code': failCode}
                },
        ),
        type: DioExceptionType.badResponse,
      ));
    } else {
      handler.resolve(Response(
        requestOptions: options,
        statusCode: 200,
        data: {
          'data': {'results': []}
        },
      ));
    }
  }));
  return (dio: dio, posted: posted);
}

Future<void> _seedGps(OfflineQueueDb db, int n) async {
  for (var i = 0; i < n; i++) {
    await db.enqueueGps(GpsQueueCompanion.insert(
      shiftId: 's1',
      latitude: 51.0 + i / 1000,
      longitude: -0.1,
      recordedAt: '2026-06-30T10:00:00Z',
      createdAt: i,
    ));
  }
}

const _farFuture = 9999999999999;

void main() {
  late OfflineQueueDb db;
  setUp(() => db = OfflineQueueDb.forTesting(NativeDatabase.memory()));
  tearDown(() => db.close());

  test('empty queue → flush is a no-op (one cycle, no posts)', () async {
    final f = _fakeDio();
    final svc = SyncFlushService(db, f.dio, () => 's1');
    await svc.flush();
    expect(f.posted, isEmpty);
    expect(svc.debugFlushCount, 1);
  });

  test('concurrent flush() calls coalesce (single-flight)', () async {
    await _seedGps(db, 3);
    final svc = SyncFlushService(db, _fakeDio().dio, () => 's1');
    await Future.wait([svc.flush(), svc.flush(), svc.flush()]);
    expect(svc.debugFlushCount, 1, reason: 'three triggers, one cycle');
  });

  test('GPS flushes as ONE batch and empties the queue', () async {
    await _seedGps(db, 3);
    final f = _fakeDio();
    final svc = SyncFlushService(db, f.dio, () => 's1');
    await svc.flush();
    expect(f.posted, hasLength(1), reason: 'single batch POST');
    expect(f.posted.first, hasLength(3));
    expect(await db.dueGps('s1', _farFuture), isEmpty);
  });

  test('GPS chunks a large backlog at 200/req', () async {
    await _seedGps(db, 250);
    final f = _fakeDio();
    final svc = SyncFlushService(db, f.dio, () => 's1');
    await svc.flush();
    expect(f.posted.map((b) => b.length).toList(), [200, 50]);
    expect(await db.dueGps('s1', _farFuture), isEmpty);
  });

  test('transient 5xx → rows kept, attempts bumped, gated behind backoff',
      () async {
    await _seedGps(db, 2);
    final svc = SyncFlushService(db, _fakeDio(failStatus: 500).dio, () => 's1');
    await svc.flush();
    final now = DateTime.now().millisecondsSinceEpoch;
    expect(await db.dueGps('s1', now), isEmpty, reason: 'gated by next_attempt');
    final all = await db.dueGps('s1', _farFuture);
    expect(all, hasLength(2));
    expect(all.every((r) => r.attempts == 1), isTrue);
  });

  test('terminal 4xx → rows dropped', () async {
    await _seedGps(db, 2);
    final svc = SyncFlushService(
        db, _fakeDio(failStatus: 422, failCode: 'VALIDATION_ERROR').dio,
        () => 's1');
    await svc.flush();
    expect(await db.dueGps('s1', _farFuture), isEmpty, reason: 'dropped');
  });

  test('no active shift → GPS not flushed', () async {
    await _seedGps(db, 2);
    final f = _fakeDio();
    final svc = SyncFlushService(db, f.dio, () => null);
    await svc.flush();
    expect(f.posted, isEmpty);
    expect(await db.dueGps('s1', _farFuture), hasLength(2));
  });
}
