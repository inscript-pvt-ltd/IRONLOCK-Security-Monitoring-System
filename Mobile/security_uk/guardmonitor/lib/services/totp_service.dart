import 'dart:typed_data';
import 'package:crypto/crypto.dart';

/// RFC-6238 TOTP used for offline wakefulness challenges. The server provisions
/// a `totp_seed` at shift start; both sides compute the same time-based code
/// without a network round-trip, so a challenge works even when the phone is
/// offline or backgrounded.
class Totp {
  const Totp._();

  /// The time-step window index for [time] — `floor(unix_seconds / period)`.
  /// This is the `window_reference` sent on an offline replay so the server
  /// knows which step the code was generated for.
  static int window(DateTime time, {int period = 30}) =>
      (time.toUtc().millisecondsSinceEpoch ~/ 1000) ~/ period;

  /// The [digits]-digit code for a given [windowIndex].
  static String codeForWindow(
    String seed,
    int windowIndex, {
    int digits = 4,
  }) {
    final key = _decodeSeed(seed);
    final msg = ByteData(8)..setUint64(0, windowIndex);
    final hmac = Hmac(sha1, key).convert(msg.buffer.asUint8List()).bytes;

    // Dynamic truncation (RFC-4226 §5.3).
    final offset = hmac[hmac.length - 1] & 0x0f;
    final binary = ((hmac[offset] & 0x7f) << 24) |
        ((hmac[offset + 1] & 0xff) << 16) |
        ((hmac[offset + 2] & 0xff) << 8) |
        (hmac[offset + 3] & 0xff);

    final mod = binary % _pow10(digits);
    return mod.toString().padLeft(digits, '0');
  }

  /// The code valid right now for [seed].
  static String codeNow(String seed, {int period = 30, int digits = 4}) =>
      codeForWindow(seed, window(DateTime.now(), period: period), digits: digits);

  static int _pow10(int n) {
    var v = 1;
    for (var i = 0; i < n; i++) {
      v *= 10;
    }
    return v;
  }

  /// Decodes the shared secret. The encoding isn't pinned by the contract, so
  /// this accepts the two common forms: base32 (RFC-4648, the TOTP norm) and
  /// hex. Falls back to raw UTF-8 bytes if it's neither.
  static Uint8List _decodeSeed(String seed) {
    final cleaned = seed.replaceAll(' ', '').replaceAll('=', '');
    final upper = cleaned.toUpperCase();
    if (RegExp(r'^[A-Z2-7]+$').hasMatch(upper)) {
      return _base32Decode(upper);
    }
    if (RegExp(r'^[0-9a-fA-F]+$').hasMatch(cleaned) && cleaned.length.isEven) {
      return _hexDecode(cleaned);
    }
    return Uint8List.fromList(seed.codeUnits);
  }

  static Uint8List _hexDecode(String hex) {
    final out = Uint8List(hex.length ~/ 2);
    for (var i = 0; i < out.length; i++) {
      out[i] = int.parse(hex.substring(i * 2, i * 2 + 2), radix: 16);
    }
    return out;
  }

  static Uint8List _base32Decode(String input) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    var bits = 0;
    var value = 0;
    final out = <int>[];
    for (final ch in input.codeUnits) {
      final idx = alphabet.indexOf(String.fromCharCode(ch));
      if (idx < 0) continue;
      value = (value << 5) | idx;
      bits += 5;
      if (bits >= 8) {
        bits -= 8;
        out.add((value >> bits) & 0xff);
      }
    }
    return Uint8List.fromList(out);
  }
}
