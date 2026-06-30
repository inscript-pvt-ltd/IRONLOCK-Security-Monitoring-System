import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/screens/home/home_screen.dart';

/// The API guide promises a `request_id` + `nonce_value` on
/// `GET /shifts/{id}/photos/pending` but never pins the envelope, so the poll
/// parser must tolerate the plausible shapes — otherwise a real-backend photo
/// request silently never opens the capture screen.
void main() {
  group('extractPendingPhoto', () {
    test('flat {pending:true, request_id, nonce_value}', () {
      final r = extractPendingPhoto(
          {'pending': true, 'request_id': 'req-1', 'nonce_value': 'n1'});
      expect(r?.requestId, 'req-1');
      expect(r?.nonceValue, 'n1');
    });

    test('bare {request_id, nonce_value} with no pending flag', () {
      final r = extractPendingPhoto({'request_id': 'req-2', 'nonce_value': 'n2'});
      expect(r?.requestId, 'req-2');
      expect(r?.nonceValue, 'n2');
    });

    test('nested under request/photo_request', () {
      final r = extractPendingPhoto({
        'request': {'request_id': 'req-3', 'nonce_value': 'n3'}
      });
      expect(r?.requestId, 'req-3');
      expect(r?.nonceValue, 'n3');
    });

    test('id/nonce field aliases', () {
      final r = extractPendingPhoto({'id': 'req-4', 'nonce': 'n4'});
      expect(r?.requestId, 'req-4');
      expect(r?.nonceValue, 'n4');
    });

    test('first element of a list', () {
      final r = extractPendingPhoto([
        {'request_id': 'req-5', 'nonce_value': 'n5'}
      ]);
      expect(r?.requestId, 'req-5');
      expect(r?.nonceValue, 'n5');
    });

    test('real backend {requests:[{...}]} array shape', () {
      // The exact envelope the production backend returns (from device logs):
      // GET /shifts/{id}/photos/pending → data: {requests: [ {...} ]}
      final r = extractPendingPhoto({
        'requests': [
          {
            'request_id': '019f035a-2775-7311-913e-a3787f0694ae',
            'nonce_value':
                '1dd54b0f4081e21b2e76da8e92ebd70f497a6a9e0ccf38156f4937da628122d8',
            'issued_at': '2026-06-26T09:54:27.000000Z',
            'expires_at': '2026-06-26T09:55:57.000000Z',
            'request_type': 'manual',
            'response_seconds': 90,
          }
        ]
      });
      expect(r?.requestId, '019f035a-2775-7311-913e-a3787f0694ae');
      expect(r?.nonceValue,
          '1dd54b0f4081e21b2e76da8e92ebd70f497a6a9e0ccf38156f4937da628122d8');
      expect(r?.responseSeconds, 90);
      expect(r?.issuedAt, DateTime.utc(2026, 6, 26, 9, 54, 27));
    });

    test('photo_requests alias array', () {
      final r = extractPendingPhoto({
        'photo_requests': [
          {'request_id': 'req-7', 'nonce_value': 'n7'}
        ]
      });
      expect(r?.requestId, 'req-7');
      expect(r?.nonceValue, 'n7');
    });

    test('empty {requests:[]} → null (nothing pending)', () {
      expect(extractPendingPhoto({'requests': <dynamic>[]}), isNull);
      expect(extractPendingPhoto({'photo_requests': <dynamic>[]}), isNull);
    });

    test('explicit pending:false → null', () {
      expect(extractPendingPhoto({'pending': false}), isNull);
    });

    test('null / empty / missing-nonce → null (no doomed capture)', () {
      expect(extractPendingPhoto(null), isNull);
      expect(extractPendingPhoto(<String, dynamic>{}), isNull);
      expect(extractPendingPhoto({'request_id': 'req-6'}), isNull); // no nonce
      expect(extractPendingPhoto(const []), isNull);
    });
  });
}
