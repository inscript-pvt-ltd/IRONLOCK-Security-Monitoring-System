import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/screens/home/home_screen.dart';

/// `GET /shifts/{id}/wakefulness/pending` promises `check_id`, `code`,
/// `issued_at`, `response_seconds`, `expires_at` but doesn't pin the envelope,
/// so the poll parser must tolerate the plausible shapes — otherwise a missed
/// push means the in-app code-entry sheet never appears.
void main() {
  group('extractPendingWakefulness', () {
    test('flat {check_id, code} with timing', () {
      final r = extractPendingWakefulness({
        'check_id': 'chk-1',
        'code': '4821',
        'issued_at': '2026-07-06T10:00:00.000Z',
        'response_seconds': '60',
      });
      expect(r?.checkId, 'chk-1');
      expect(r?.code, '4821');
      expect(r?.responseSeconds, 60);
      expect(r?.issuedAt, DateTime.utc(2026, 7, 6, 10));
    });

    test('nested under {challenge:{...}}', () {
      final r = extractPendingWakefulness({
        'challenge': {'check_id': 'chk-2', 'code': '1111'}
      });
      expect(r?.checkId, 'chk-2');
      expect(r?.code, '1111');
    });

    test('array {challenges:[{...}]} takes the first', () {
      final r = extractPendingWakefulness({
        'challenges': [
          {'check_id': 'chk-3', 'code': '2222'}
        ]
      });
      expect(r?.checkId, 'chk-3');
    });

    test('empty {challenges:[]} → nothing pending', () {
      expect(extractPendingWakefulness({'challenges': <dynamic>[]}), isNull);
    });

    test('{pending:false} → null even if other keys linger', () {
      expect(
        extractPendingWakefulness(
            {'pending': false, 'check_id': 'x', 'code': '9'}),
        isNull,
      );
    });

    test('missing code → null (nothing to raise)', () {
      expect(extractPendingWakefulness({'check_id': 'chk-4'}), isNull);
    });

    test('expires_at back-computes issued_at from response_seconds', () {
      final r = extractPendingWakefulness({
        'check_id': 'chk-5',
        'code': '3333',
        'expires_at': '2026-07-06T10:01:00.000Z',
        'response_seconds': '60',
      });
      expect(r?.issuedAt, DateTime.utc(2026, 7, 6, 10)); // 10:01 − 60s
    });

    // The exact envelope Jerry pinned in BACKEND_REPLY_2026-07-21.md: challenges
    // under data.challenges[], empty array = nothing pending, code zero-padded.
    test('confirmed backend envelope: data.challenges[0]', () {
      final data = {
        'challenges': [
          {
            'check_id': '9f1c',
            'shift_id': '3ab7',
            'code': '0472',
            'request_type': 'scheduled',
            'scheduled_at': '2026-07-21T10:00:00.000000Z',
            'issued_at': '2026-07-21T10:00:00.000000Z',
            'response_seconds': 60,
            'expires_at': '2026-07-21T10:01:00.000000Z',
          }
        ]
      };
      final r = extractPendingWakefulness(data);
      expect(r?.checkId, '9f1c');
      expect(r?.code, '0472');
      expect(r?.responseSeconds, 60);
      expect(r?.issuedAt, DateTime.utc(2026, 7, 21, 10));
    });

    test('confirmed backend envelope: empty challenges[] → nothing pending', () {
      expect(extractPendingWakefulness({'challenges': <dynamic>[]}), isNull);
    });
  });
}
