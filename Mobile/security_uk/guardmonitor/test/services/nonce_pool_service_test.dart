import 'package:dio/dio.dart';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/data/offline_queue_db.dart';
import 'package:guardmonitor/services/nonce_pool_service.dart';

String _iso(Duration fromNow) =>
    DateTime.now().toUtc().add(fromNow).toIso8601String();

/// Dio that returns [body] from the prefetch endpoint and counts calls.
({Dio dio, List<String> paths}) _fakeDio(Map<String, dynamic>? body,
    {int status = 200}) {
  final paths = <String>[];
  final dio = Dio();
  dio.interceptors.add(InterceptorsWrapper(onRequest: (options, handler) {
    paths.add(options.path);
    if (status >= 400) {
      handler.reject(DioException(
        requestOptions: options,
        response: Response(requestOptions: options, statusCode: status),
        type: DioExceptionType.badResponse,
      ));
    } else {
      handler.resolve(
          Response(requestOptions: options, statusCode: 200, data: body));
    }
  }));
  return (dio: dio, paths: paths);
}

void main() {
  late OfflineQueueDb db;
  setUp(() => db = OfflineQueueDb.forTesting(NativeDatabase.memory()));
  tearDown(() => db.close());

  group('parsePrefetch', () {
    test('parses well-formed nonces and skips malformed ones', () {
      final body = {
        'data': {
          'nonces': [
            {'nonce_value': 'aaa', 'expires_at': _iso(const Duration(minutes: 15)), 'type': 'OFFLINE_POOL'},
            {'nonce_value': 'bbb', 'expires_at': _iso(const Duration(minutes: 15))},
            {'nonce_value': 'ccc'}, // no expiry → skipped
            {'expires_at': _iso(const Duration(minutes: 15))}, // no value → skipped
            'garbage',
          ],
        },
      };
      final out = NoncePoolService.parsePrefetch(body, 's1');
      expect(out, hasLength(2));
    });

    test('tolerates a missing/odd body', () {
      expect(NoncePoolService.parsePrefetch(null, 's1'), isEmpty);
      expect(NoncePoolService.parsePrefetch({'data': {}}, 's1'), isEmpty);
      expect(NoncePoolService.parsePrefetch({'data': {'nonces': 'x'}}, 's1'),
          isEmpty);
    });
  });

  group('refillIfLow', () {
    test('fetches and stores when the pool is below threshold', () async {
      final body = {
        'data': {
          'nonces': [
            for (var i = 0; i < 20; i++)
              {
                'nonce_value': 'n$i',
                'expires_at': _iso(const Duration(minutes: 15)),
                'type': 'OFFLINE_POOL',
              }
          ],
        },
      };
      final f = _fakeDio(body);
      final svc = NoncePoolService(f.dio, db);
      await svc.refillIfLow('s1');
      expect(f.paths.single, contains('/shifts/s1/nonces/prefetch'));
      expect(await svc.available('s1'), 20);
    });

    test('does NOT fetch when the pool is already healthy', () async {
      await db.insertNonces([
        for (var i = 0; i < 10; i++)
          NoncePoolCompanion.insert(
            nonceValue: 'have$i',
            shiftId: 's1',
            expiresAt:
                DateTime.now().add(const Duration(minutes: 15)).millisecondsSinceEpoch,
          ),
      ]);
      final f = _fakeDio({'data': {'nonces': []}});
      final svc = NoncePoolService(f.dio, db);
      await svc.refillIfLow('s1');
      expect(f.paths, isEmpty, reason: 'no network call when pool is full');
    });

    test('a 409/offline failure leaves the existing pool untouched', () async {
      await db.insertNonces([
        NoncePoolCompanion.insert(
          nonceValue: 'keep',
          shiftId: 's1',
          expiresAt:
              DateTime.now().add(const Duration(minutes: 15)).millisecondsSinceEpoch,
        ),
      ]);
      final svc = NoncePoolService(_fakeDio(null, status: 409).dio, db);
      await svc.refillIfLow('s1'); // pool has 1 (<5) so it tries, then fails
      expect(await svc.available('s1'), 1);
    });
  });

  test('draw claims a single-use nonce and empties when dry', () async {
    final body = {
      'data': {
        'nonces': [
          {'nonce_value': 'only', 'expires_at': _iso(const Duration(minutes: 15))}
        ]
      }
    };
    final svc = NoncePoolService(_fakeDio(body).dio, db);
    await svc.refillIfLow('s1');
    expect(await svc.draw('s1'), 'only');
    expect(await svc.draw('s1'), isNull, reason: 'single-use');
  });
}
