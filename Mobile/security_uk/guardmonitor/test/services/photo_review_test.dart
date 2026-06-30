import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/photo_review_service.dart';

void main() {
  group('PhotoReview.fromJson', () {
    test('parses an approval (note optional)', () {
      final r = PhotoReview.fromJson({
        'request_id': 'req-1',
        'decision': 'APPROVED',
        'note': null,
        'reviewed_at': '2026-06-26T14:31:02.000000Z',
      });
      expect(r.requestId, 'req-1');
      expect(r.approved, isTrue);
      expect(r.note, isNull);
      expect(r.reviewedAt, DateTime.utc(2026, 6, 26, 14, 31, 2));
    });

    test('parses a rejection with a note', () {
      final r = PhotoReview.fromJson({
        'request_id': 'req-2',
        'decision': 'REJECTED',
        'note': '  Too dark  ',
      });
      expect(r.approved, isFalse);
      expect(r.note, 'Too dark'); // trimmed
    });

    test('blank/whitespace note becomes null', () {
      final r = PhotoReview.fromJson(
          {'request_id': 'r', 'decision': 'APPROVED', 'note': '   '});
      expect(r.note, isNull);
    });

    test('missing fields degrade safely (empty strings)', () {
      final r = PhotoReview.fromJson(<String, dynamic>{});
      expect(r.requestId, isEmpty);
      expect(r.decision, isEmpty);
      expect(r.reviewedAt, isNull);
    });
  });
}
