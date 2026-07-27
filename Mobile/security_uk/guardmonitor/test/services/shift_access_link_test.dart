import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/shift_access_link.dart';

// A real 64-char hex token (from the API guide example).
const _token =
    'eea5367f3261798777ad69d8aa651e6731f663d7810ca60edf358700f9fc150f';

DioException _dioWith(String code, {Map<String, dynamic>? details}) {
  final req = RequestOptions(path: '/auth/shift-access');
  return DioException(
    requestOptions: req,
    response: Response<Map<String, dynamic>>(
      requestOptions: req,
      statusCode: 401,
      data: {
        'success': false,
        'error': {
          'code': code,
          'message': 'server message for $code',
          'details': ?details,
        },
      },
    ),
  );
}

void main() {
  group('extractShiftAccessToken', () {
    test('production https link → token', () {
      expect(
        extractShiftAccessToken(
            Uri.parse('https://example.cpanel.site/m/shift-access/$_token')),
        _token,
      );
    });

    test('custom scheme link → token', () {
      expect(
        extractShiftAccessToken(Uri.parse('ironlock://shift-access/$_token')),
        _token,
      );
    });

    test('uppercase hex is accepted', () {
      final upper = _token.toUpperCase();
      expect(
        extractShiftAccessToken(Uri.parse('ironlock://shift-access/$upper')),
        upper,
      );
    });

    test('bare link with no token → null', () {
      expect(
        extractShiftAccessToken(
            Uri.parse('https://example.cpanel.site/m/shift-access')),
        isNull,
      );
      expect(
        extractShiftAccessToken(Uri.parse('ironlock://shift-access')),
        isNull,
      );
    });

    test('non-shift-access deep link → null', () {
      expect(extractShiftAccessToken(Uri.parse('ironlock://something/$_token')),
          isNull);
      expect(
          extractShiftAccessToken(Uri.parse('https://example.com/other/$_token')),
          isNull);
    });

    test('token of the wrong shape → null', () {
      expect(
        extractShiftAccessToken(Uri.parse('ironlock://shift-access/not-hex')),
        isNull,
      );
      expect(
        extractShiftAccessToken(Uri.parse('ironlock://shift-access/abc123')),
        isNull, // too short
      );
    });
  });

  group('ShiftAccessException.fromDio', () {
    test('maps each SHIFT_ACCESS_* code to guard-facing copy', () {
      for (final code in [
        'SHIFT_ACCESS_INVALID',
        'SHIFT_ACCESS_EXPIRED',
        'SHIFT_ACCESS_USED',
        'SHIFT_ACCESS_SHIFT_INVALID',
      ]) {
        final ex = ShiftAccessException.fromDio(_dioWith(code));
        expect(ex.code, code);
        expect(ex.message, isNotEmpty);
        expect(ex.windowExpired, isFalse);
      }
    });

    test('LOGIN_WINDOW_CLOSED expired flags windowExpired + own copy (not server msg)',
        () {
      final ex = ShiftAccessException.fromDio(
          _dioWith('LOGIN_WINDOW_CLOSED', details: {'reason': 'expired'}));
      expect(ex.windowExpired, isTrue);
      // We render our own copy, NOT the server message (which may be wrong-zone).
      expect(ex.message, isNot(contains('server message')));
      expect(ex.message, contains('closed'));
    });

    test('LOGIN_WINDOW_CLOSED too_early does NOT flag windowExpired', () {
      final ex = ShiftAccessException.fromDio(
          _dioWith('LOGIN_WINDOW_CLOSED', details: {'reason': 'too_early'}));
      expect(ex.windowExpired, isFalse);
    });

    test('LOGIN_WINDOW_CLOSED too_early localizes the details timestamps', () {
      // 05:40Z / 05:55Z → formatted in the DEVICE zone, never raw UTC.
      final ex = ShiftAccessException.fromDio(
          _dioWith('LOGIN_WINDOW_CLOSED', details: {
        'reason': 'too_early',
        'window_opens_at': '2026-07-23T05:40:00.000000Z',
        'next_shift_start': '2026-07-23T05:55:00.000000Z',
      }));
      // Build the same expectation from .toLocal() so the test is zone-agnostic
      // (passes wherever CI runs), and prove it's NOT the raw server message.
      String hhmm(String iso) {
        final l = DateTime.parse(iso).toLocal();
        return '${l.hour.toString().padLeft(2, '0')}:'
            '${l.minute.toString().padLeft(2, '0')}';
      }
      expect(ex.message, isNot(contains('server message')));
      expect(
          ex.message,
          'You can sign in from ${hhmm('2026-07-23T05:40:00Z')} — '
          '15 minutes before your ${hhmm('2026-07-23T05:55:00Z')} shift.');
    });

    test('LOGIN_WINDOW_CLOSED too_early with missing timestamps → server fallback',
        () {
      final ex = ShiftAccessException.fromDio(
          _dioWith('LOGIN_WINDOW_CLOSED', details: {'reason': 'too_early'}));
      expect(ex.message, contains('server message'));
    });

    test('LOGIN_WINDOW_CLOSED no_shift → own copy', () {
      final ex = ShiftAccessException.fromDio(
          _dioWith('LOGIN_WINDOW_CLOSED', details: {'reason': 'no_shift'}));
      expect(ex.windowExpired, isFalse);
      expect(ex.message, isNot(contains('server message')));
    });

    test('unknown code falls back to the server message', () {
      final ex = ShiftAccessException.fromDio(_dioWith('SHIFT_ACCESS_UNAUTHORIZED'));
      expect(ex.code, 'SHIFT_ACCESS_UNAUTHORIZED');
      expect(ex.message, contains('server message'));
    });
  });
}
