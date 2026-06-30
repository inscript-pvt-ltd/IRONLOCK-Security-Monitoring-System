import 'dart:convert';
import 'dart:io';

import 'package:dio/dio.dart';
import 'package:drift/drift.dart' show Value;
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/data/offline_queue_db.dart';
import 'package:guardmonitor/services/sync_flush_service.dart';

/// Builds a Dio whose every request is short-circuited: it records the posted
/// `pings` (GPS) and request paths, and either resolves 200 or rejects with
/// [failStatus]/[failCode].
({
  Dio dio,
  List<List<dynamic>> posted,
  List<String> paths,
  List<Map<String, String>> forms,
}) _fakeDio({
  int? failStatus,
  String? failCode,
}) {
  final posted = <List<dynamic>>[];
  final paths = <String>[];
  final forms = <Map<String, String>>[];
  final dio = Dio();
  dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
    paths.add(options.path);
    final data = options.data;
    if (data is Map && data['pings'] is List) {
      posted.add(data['pings'] as List);
    }
    if (data is FormData) {
      forms.add({for (final e in data.fields) e.key: e.value});
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
  return (dio: dio, posted: posted, paths: paths, forms: forms);
}

/// Writes a real temp JPEG and queues a 1-image offline photo row referencing it.
Future<({int id, String path})> _seedPhoto(
  OfflineQueueDb db, {
  String signature = 'STORED_SIG',
  String? ntpReference = '2026-06-30T10:00:00.000Z',
  String capturedAt = '2026-06-30T10:00:00.000Z',
  int createdAt = 0,
}) async {
  final file = File(
      '${Directory.systemTemp.path}/qphoto_${DateTime.now().microsecondsSinceEpoch}.jpg');
  await file.writeAsBytes([1, 2, 3, 4]);
  final id = await db.enqueuePhoto(PhotoQueueCompanion.insert(
    shiftId: 's1',
    nonceValue: 'POOL_NONCE',
    filePaths: jsonEncode([file.path]),
    signatures: jsonEncode([signature]),
    ntpReference: Value(ntpReference),
    elapsedSeconds: 0,
    capturedAt: capturedAt,
    createdAt: createdAt,
  ));
  return (id: id, path: file.path);
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

Future<void> _seedWake(OfflineQueueDb db, {int createdAt = 0}) =>
    db.enqueueWakefulness(WakefulnessQueueCompanion.insert(
      checkId: 'chk1',
      code: '4821',
      windowReference: 1782342,
      respondedAt: '2026-06-30T10:00:00Z',
      createdAt: createdAt,
    ));

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

  group('wakefulness flush', () {
    test('posts the queued answer and dequeues on success', () async {
      await _seedWake(db);
      final f = _fakeDio();
      final svc = SyncFlushService(db, f.dio, () => 's1');
      await svc.flush();
      expect(f.paths.any((p) => p.contains('/wakefulness/chk1/respond')), isTrue);
      expect(await db.dueWakefulness(_farFuture), isEmpty);
    });

    test('ALREADY_RESOLVED is treated as success (dequeued)', () async {
      await _seedWake(db);
      final svc = SyncFlushService(
          db, _fakeDio(failStatus: 409, failCode: 'ALREADY_RESOLVED').dio,
          () => 's1');
      await svc.flush();
      expect(await db.dueWakefulness(_farFuture), isEmpty);
    });

    test('transient 5xx keeps the row and bumps attempts', () async {
      await _seedWake(db);
      final svc = SyncFlushService(db, _fakeDio(failStatus: 503).dio, () => 's1');
      await svc.flush();
      expect(await db.dueWakefulness(DateTime.now().millisecondsSinceEpoch),
          isEmpty,
          reason: 'gated by backoff');
      final all = await db.dueWakefulness(_farFuture);
      expect(all.single.attempts, 1);
    });

    test('flushes even with no active shift (check may be from a past shift)',
        () async {
      await _seedWake(db);
      final f = _fakeDio();
      final svc = SyncFlushService(db, f.dio, () => null);
      await svc.flush();
      expect(await db.dueWakefulness(_farFuture), isEmpty);
    });
  });

  group('photo flush', () {
    test('re-sends the STORED signature + nonce + ntp_reference verbatim',
        () async {
      final seeded = await _seedPhoto(db, signature: 'SIG_ABC');
      final f = _fakeDio();
      final svc = SyncFlushService(db, f.dio, () => 's1');
      await svc.flush();

      expect(f.paths.any((p) => p.contains('/shifts/s1/photos')), isTrue);
      final form = f.forms.single;
      expect(form['signature'], 'SIG_ABC',
          reason: 'signature must NOT be recomputed');
      expect(form['nonce_value'], 'POOL_NONCE');
      expect(form['ntp_reference'], '2026-06-30T10:00:00.000Z');
      expect(form['captured_at'], '2026-06-30T10:00:00.000Z');
      expect(form.containsKey('request_id'), isFalse,
          reason: 'offline photos omit request_id');
      // Dequeued + durable file cleaned up.
      expect(await db.duePhotos('s1', _farFuture), isEmpty);
      expect(await File(seeded.path).exists(), isFalse);
    });

    test('NONCE_ALREADY_USED (422 PHOTO_REJECTED) → success, dequeued',
        () async {
      await _seedPhoto(db);
      final dio = Dio();
      dio.interceptors.add(InterceptorsWrapper(onRequest: (o, h) {
        h.reject(DioException(
          requestOptions: o,
          response: Response(requestOptions: o, statusCode: 422, data: {
            'error': {
              'code': 'PHOTO_REJECTED',
              'details': {'reason': 'NONCE_ALREADY_USED'}
            }
          }),
          type: DioExceptionType.badResponse,
        ));
      }));
      final svc = SyncFlushService(db, dio, () => 's1');
      await svc.flush();
      expect(await db.duePhotos('s1', _farFuture), isEmpty);
    });

    test('transient 5xx keeps the row and bumps attempts', () async {
      await _seedPhoto(db);
      final svc = SyncFlushService(db, _fakeDio(failStatus: 503).dio, () => 's1');
      await svc.flush();
      expect(await db.duePhotos('s1', DateTime.now().millisecondsSinceEpoch),
          isEmpty);
      final all = await db.duePhotos('s1', _farFuture);
      expect(all.single.attempts, 1);
    });
  });

  test('flush order: wakefulness before GPS', () async {
    await _seedWake(db);
    await _seedGps(db, 1);
    final f = _fakeDio();
    final svc = SyncFlushService(db, f.dio, () => 's1');
    await svc.flush();
    final wakeIdx = f.paths.indexWhere((p) => p.contains('/wakefulness/'));
    final gpsIdx = f.paths.indexWhere((p) => p.contains('/locations'));
    expect(wakeIdx, isNonNegative);
    expect(gpsIdx, isNonNegative);
    expect(wakeIdx, lessThan(gpsIdx), reason: 'wakefulness flushes first');
  });
}
