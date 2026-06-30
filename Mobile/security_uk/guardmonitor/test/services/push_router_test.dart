import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/push_router.dart';

/// Pins the FCM push data contract the app consumes. The backend must send
/// these exact fields (all string-valued, as FCM data always is).
void main() {
  group('PushMessage.fromData', () {
    test('parses a WAKEFULNESS_CHALLENGE push', () {
      final m = PushMessage.fromData({
        'type': 'WAKEFULNESS_CHALLENGE',
        'check_id': 'chk-1',
        'shift_id': 'shift-1',
        'code': '4821',
        'response_seconds': '60',
      });
      expect(m.kind, PushKind.wakefulness);
      expect(m.checkId, 'chk-1');
      expect(m.shiftId, 'shift-1');
      expect(m.code, '4821');
      expect(m.responseSeconds, 60);
      expect(m.isActionable, isTrue);
    });

    test('parses a PHOTO_REQUEST push', () {
      final m = PushMessage.fromData({
        'type': 'PHOTO_REQUEST',
        'request_id': 'req-9',
        'shift_id': 'shift-1',
        'nonce_value': 'n0nce',
      });
      expect(m.kind, PushKind.photo);
      expect(m.requestId, 'req-9');
      expect(m.shiftId, 'shift-1');
      expect(m.nonceValue, 'n0nce');
      expect(m.isActionable, isTrue);
      // Backend doesn't send timing today — both must parse as null.
      expect(m.issuedAt, isNull);
      expect(m.responseSeconds, isNull);
    });

    test('parses optional issued_at + response_seconds on a PHOTO_REQUEST', () {
      final m = PushMessage.fromData({
        'type': 'PHOTO_REQUEST',
        'request_id': 'req-9',
        'nonce_value': 'n0nce',
        'issued_at': '2026-06-24T10:00:00Z',
        'response_seconds': '90',
      });
      expect(m.issuedAt, DateTime.utc(2026, 6, 24, 10));
      expect(m.responseSeconds, 90);
    });

    test('parses issued_at when present (for server-anchored countdown)', () {
      final m = PushMessage.fromData({
        'type': 'WAKEFULNESS_CHALLENGE',
        'check_id': 'chk-1',
        'code': '4821',
        'issued_at': '2026-06-24T10:00:00Z',
      });
      expect(m.issuedAt, DateTime.utc(2026, 6, 24, 10));
    });

    test('issued_at is null when absent or malformed', () {
      final m = PushMessage.fromData({
        'type': 'WAKEFULNESS_CHALLENGE',
        'check_id': 'chk-1',
        'code': '4821',
      });
      expect(m.issuedAt, isNull);
    });

    test('an unknown type is not actionable', () {
      final m = PushMessage.fromData({'type': 'something-else'});
      expect(m.kind, PushKind.unknown);
      expect(m.isActionable, isFalse);
    });

    test('a wakefulness push missing its code is not actionable', () {
      final m =
          PushMessage.fromData({'type': 'WAKEFULNESS_CHALLENGE', 'check_id': 'x'});
      expect(m.isActionable, isFalse);
    });
  });

  group('routePush dispatch', () {
    test('wakefulness confirms receipt THEN raises the challenge', () {
      final calls = <String>[];
      routePush(
        {'type': 'WAKEFULNESS_CHALLENGE', 'check_id': 'chk-1', 'code': '4821'},
        onConfirmReceived: (id) => calls.add('received:$id'),
        onWakefulness: (id, code, secs, issuedAt) =>
            calls.add('challenge:$id:$code'),
        onPhoto: (_, _, _, _) => calls.add('photo'),
        onPhotoReviewed: (_, _, _, _) => calls.add('reviewed'),
      );
      // Receipt must come first so a dropped push can't look like a no-answer.
      expect(calls, ['received:chk-1', 'challenge:chk-1:4821']);
    });

    test('photo opens the capture flow and does NOT confirm receipt', () {
      final calls = <String>[];
      routePush(
        {'type': 'PHOTO_REQUEST', 'request_id': 'req-9', 'nonce_value': 'n0nce'},
        onConfirmReceived: (id) => calls.add('received:$id'),
        onWakefulness: (_, _, _, _) => calls.add('challenge'),
        onPhoto: (req, nonce, issuedAt, secs) =>
            calls.add('photo:$req:$nonce:$issuedAt:$secs'),
        onPhotoReviewed: (_, _, _, _) => calls.add('reviewed'),
      );
      // No timing fields today → both null forwarded to the capture flow.
      expect(calls, ['photo:req-9:n0nce:null:null']);
    });

    test('photo forwards issued_at + response_seconds when present', () {
      DateTime? gotIssuedAt;
      int? gotSecs;
      routePush(
        {
          'type': 'PHOTO_REQUEST',
          'request_id': 'req-9',
          'nonce_value': 'n0nce',
          'issued_at': '2026-06-24T10:00:00Z',
          'response_seconds': '90',
        },
        onConfirmReceived: (_) {},
        onWakefulness: (_, _, _, _) {},
        onPhoto: (req, nonce, issuedAt, secs) {
          gotIssuedAt = issuedAt;
          gotSecs = secs;
        },
        onPhotoReviewed: (_, _, _, _) {},
      );
      expect(gotIssuedAt, DateTime.utc(2026, 6, 24, 10));
      expect(gotSecs, 90);
    });

    test('an incomplete push triggers nothing', () {
      final calls = <String>[];
      routePush(
        {'type': 'WAKEFULNESS_CHALLENGE', 'check_id': 'x'}, // no code
        onConfirmReceived: (_) => calls.add('received'),
        onWakefulness: (_, _, _, _) => calls.add('challenge'),
        onPhoto: (_, _, _, _) => calls.add('photo'),
        onPhotoReviewed: (_, _, _, _) => calls.add('reviewed'),
      );
      expect(calls, isEmpty);
    });

    test('photo_reviewed forwards decision + note, no receipt', () {
      final calls = <String>[];
      routePush(
        {
          'type': 'PHOTO_REVIEWED',
          'request_id': 'req-7',
          'shift_id': 'shift-1',
          'decision': 'REJECTED',
          'note': 'Too dark',
        },
        onConfirmReceived: (id) => calls.add('received:$id'),
        onWakefulness: (_, _, _, _) => calls.add('challenge'),
        onPhoto: (_, _, _, _) => calls.add('photo'),
        onPhotoReviewed: (req, shift, decision, note) =>
            calls.add('reviewed:$req:$shift:$decision:$note'),
      );
      expect(calls, ['reviewed:req-7:shift-1:REJECTED:Too dark']);
    });

    test('photo_reviewed without a decision is not actionable', () {
      final calls = <String>[];
      routePush(
        {'type': 'PHOTO_REVIEWED', 'request_id': 'req-7'}, // no decision
        onConfirmReceived: (_) {},
        onWakefulness: (_, _, _, _) {},
        onPhoto: (_, _, _, _) {},
        onPhotoReviewed: (_, _, _, _) => calls.add('reviewed'),
      );
      expect(calls, isEmpty);
    });
  });
}
