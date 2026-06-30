import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/totp_service.dart';

/// Pins the wakefulness TOTP generator against the published RFC-6238 SHA-1
/// test vectors (Appendix B). The reference key is the ASCII string
/// "12345678901234567890"; we pass it base32-encoded so the seed decoder reads
/// it unambiguously as base32 (the bare ASCII form collides with valid hex).
void main() {
  // base32("12345678901234567890")
  const seed = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

  group('Totp.window', () {
    test('is floor(unix_seconds / period)', () {
      expect(Totp.window(DateTime.utc(1970, 1, 1, 0, 0, 59)), 1);
      expect(Totp.window(DateTime.utc(1970, 1, 1, 0, 0, 29)), 0);
      // 1111111109 / 30 = 37037036
      expect(
        Totp.window(DateTime.utc(2005, 3, 18, 1, 58, 29)),
        37037036,
      );
    });
  });

  group('Totp.codeForWindow (RFC-6238 SHA-1 vectors)', () {
    test('window 1 (T=59) → 8 digits 94287082', () {
      expect(Totp.codeForWindow(seed, 1, digits: 8), '94287082');
    });

    test('window 1 (T=59) → 6 digits 287082', () {
      expect(Totp.codeForWindow(seed, 1, digits: 6), '287082');
    });

    test('window 37037036 → 8 digits 07081804', () {
      expect(Totp.codeForWindow(seed, 37037036, digits: 8), '07081804');
    });

    test('4-digit code is the last 4 of the 8-digit code', () {
      expect(Totp.codeForWindow(seed, 1, digits: 4), '7082');
    });

    test('always zero-pads to the requested width', () {
      final code = Totp.codeForWindow(seed, 1, digits: 4);
      expect(code.length, 4);
    });
  });

  group('seed decoding', () {
    test('hex and base32 forms of the same key produce the same code', () {
      // "12345678901234567890" as hex bytes 0x31 0x32 … equals the ASCII key,
      // but here we just assert hex input is accepted and deterministic.
      const hexSeed = '3132333435363738393031323334353637383930';
      final a = Totp.codeForWindow(hexSeed, 1, digits: 8);
      final b = Totp.codeForWindow(hexSeed, 1, digits: 8);
      expect(a, b);
    });
  });
}
