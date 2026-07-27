import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/utils/server_time.dart';

void main() {
  group('parseServerUtc (M5)', () {
    test('a zone-less string is interpreted as UTC, not device-local', () {
      final dt = parseServerUtc('2026-07-24T13:00:00');
      expect(dt, isNotNull);
      expect(dt!.isUtc, isTrue);
      expect(dt.hour, 13, reason: 'read as 13:00 UTC, not shifted by device offset');
    });

    test('a trailing Z is honoured', () {
      final dt = parseServerUtc('2026-07-24T13:00:00Z');
      expect(dt!.toUtc().hour, 13);
    });

    test('a numeric offset resolves to the correct UTC instant', () {
      // 13:00 at +05:00 == 08:00 UTC.
      final dt = parseServerUtc('2026-07-24T13:00:00+05:00');
      expect(dt!.isUtc, isTrue);
      expect(dt.hour, 8);
    });

    test('null / blank / garbage → null', () {
      expect(parseServerUtc(null), isNull);
      expect(parseServerUtc('   '), isNull);
      expect(parseServerUtc('not-a-date'), isNull);
    });
  });
}
