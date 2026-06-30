import 'dart:math';

import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/photo_service.dart';
import 'package:guardmonitor/services/sync_retry.dart';

DioException _dio(int? status, {Object? body}) => DioException(
      requestOptions: RequestOptions(path: '/x'),
      response: status == null
          ? null
          : Response(
              requestOptions: RequestOptions(path: '/x'),
              statusCode: status,
              data: body,
            ),
      type: status == null
          ? DioExceptionType.connectionTimeout
          : DioExceptionType.badResponse,
    );

Map<String, dynamic> _err(String code) => {
      'error': {'code': code, 'message': 'msg'},
    };

void main() {
  group('classifyFlush — the §4 retry table', () {
    test('no error → success', () {
      expect(classifyFlush(null).action, FlushAction.success);
    });

    test('timeout / no response → retry', () {
      expect(classifyFlush(_dio(null)).action, FlushAction.retry);
    });

    test('5xx → retry', () {
      expect(classifyFlush(_dio(500)).action, FlushAction.retry);
      expect(classifyFlush(_dio(503)).action, FlushAction.retry);
    });

    test('ALREADY_RESOLVED (wakefulness) → success', () {
      final d = classifyFlush(_dio(409, body: _err('ALREADY_RESOLVED')));
      expect(d.action, FlushAction.success);
      expect(d.code, 'ALREADY_RESOLVED');
    });

    test('NONCE_ALREADY_USED (photo, as Dio) → success', () {
      expect(classifyFlush(_dio(409, body: _err('NONCE_ALREADY_USED'))).action,
          FlushAction.success);
    });

    test('NONCE_ALREADY_USED (photo, as typed exception) → success', () {
      expect(
        classifyFlush(const PhotoRejectedException('NONCE_ALREADY_USED')).action,
        FlushAction.success,
      );
    });

    test('VALIDATION_ERROR / SHIFT_NOT_ACTIVE / CHECK_NOT_FOUND / NONCE_NOT_FOUND → drop',
        () {
      for (final code in [
        'VALIDATION_ERROR',
        'SHIFT_NOT_ACTIVE',
        'CHECK_NOT_FOUND',
        'NONCE_NOT_FOUND',
      ]) {
        expect(classifyFlush(_dio(422, body: _err(code))).action,
            FlushAction.drop,
            reason: code);
      }
    });

    test('unknown 4xx → drop (never loops forever)', () {
      expect(classifyFlush(_dio(418, body: _err('WAT'))).action,
          FlushAction.drop);
      expect(classifyFlush(_dio(400)).action, FlushAction.drop);
    });

    test('photo rejection other than already-used → drop', () {
      expect(classifyFlush(const PhotoRejectedException('HMAC_INVALID')).action,
          FlushAction.drop);
    });

    test('non-network error → drop', () {
      expect(classifyFlush(StateError('file gone')).action, FlushAction.drop);
    });
  });

  group('backoffDelay', () {
    test('grows exponentially and is capped', () {
      Duration at(int n) => backoffDelay(n,
          base: const Duration(seconds: 2),
          cap: const Duration(minutes: 5),
          random: _ZeroRandom());
      // base 2s: 2,4,8,16s … capped at 5min (300s).
      expect(at(0).inSeconds, 2);
      expect(at(1).inSeconds, 4);
      expect(at(2).inSeconds, 8);
      expect(at(20).inSeconds, 300, reason: 'capped at 5 min');
      // Large attempt counts don't overflow.
      expect(at(100).inSeconds, 300);
    });

    test('jitter adds at most 25%', () {
      final d = backoffDelay(0,
          base: const Duration(seconds: 100),
          random: _MaxRandom()); // nextDouble → ~1.0
      expect(d.inMilliseconds, inInclusiveRange(100000, 125000));
    });
  });
}

class _ZeroRandom implements Random {
  @override
  double nextDouble() => 0;
  @override
  bool nextBool() => false;
  @override
  int nextInt(int max) => 0;
}

class _MaxRandom implements Random {
  @override
  double nextDouble() => 0.999999;
  @override
  bool nextBool() => true;
  @override
  int nextInt(int max) => max - 1;
}
