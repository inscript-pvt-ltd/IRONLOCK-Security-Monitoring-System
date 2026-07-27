import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/totp_service.dart';

/// Byte-for-byte parity with the backend's TOTP (Jerry, 2026-07-27).
/// Base32 secret, SHA-1, 4 digits. The **leading-zero** windows are the ones
/// that matter — if our impl returns `690` instead of `0690`, offline wakefulness
/// silently fails ~10% of the time.
void main() {
  const seed = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
  String code(int window) => Totp.codeForWindow(seed, window, digits: 4);

  group('backend TOTP vectors — general', () {
    const vectors = {
      0: '2218',
      1: '3347',
      58000000: '8889',
      58000001: '7226',
      58123456: '1303',
      58123457: '5024',
      58123458: '4068',
    };
    vectors.forEach((window, expected) {
      test('window $window → $expected', () => expect(code(window), expected));
    });
  });

  group('backend TOTP vectors — LEADING ZERO (the critical ones)', () {
    const vectors = {
      58000004: '0690',
      58000011: '0104',
      58000017: '0403',
      58000048: '0468',
      58000072: '0372',
      58000105: '0428',
    };
    vectors.forEach((window, expected) {
      test('window $window → $expected (4 chars, zero kept)', () {
        final c = code(window);
        expect(c, expected);
        expect(c.length, 4, reason: 'must stay 4 chars, never drop the zero');
      });
    });
  });
}
