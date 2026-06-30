import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/photo_service.dart';

/// The multi-photo (1–5) upload signs each image with the SAME shared fields
/// (nonce, request_id, captured_at, lat, lng) and only that image's own
/// `sha256` differs — so each image's signature must differ accordingly, and
/// must still match a server re-hashing the six `\n`-joined fields byte-for-byte.
void main() {
  const secret = 'f3a9c1deadbeef00';
  const nonce = 'nonce-123';
  const requestId = 'req-456';
  const capturedAt = '2026-06-26T10:00:00.000Z';

  String expected(String imageHash, {String req = requestId, String lat = '', String lng = ''}) {
    final message = [nonce, req, capturedAt, lat, lng, imageHash].join('\n');
    return Hmac(sha256, utf8.encode(secret)).convert(utf8.encode(message)).toString();
  }

  group('PhotoService.buildSignature', () {
    test('matches an independent HMAC over the 6 \\n-joined fields', () {
      const hash = 'aaaa';
      final sig = PhotoService.buildSignature(
        secret: secret,
        nonceValue: nonce,
        requestId: requestId,
        capturedAt: capturedAt,
        latitude: '',
        longitude: '',
        imageHash: hash,
      );
      expect(sig, expected(hash));
    });

    test('two images in a batch differ ONLY by image hash → different sigs', () {
      String sigFor(String hash) => PhotoService.buildSignature(
            secret: secret,
            nonceValue: nonce,
            requestId: requestId,
            capturedAt: capturedAt,
            latitude: '51.5',
            longitude: '-0.1',
            imageHash: hash,
          );
      final a = sigFor('hash-A');
      final b = sigFor('hash-B');
      expect(a, isNot(b));
      expect(a, expected('hash-A', lat: '51.5', lng: '-0.1'));
      expect(b, expected('hash-B', lat: '51.5', lng: '-0.1'));
    });

    test('identical inputs are deterministic (server re-hash matches)', () {
      final a = PhotoService.buildSignature(
        secret: secret, nonceValue: nonce, requestId: requestId,
        capturedAt: capturedAt, latitude: '', longitude: '', imageHash: 'x',
      );
      final b = PhotoService.buildSignature(
        secret: secret, nonceValue: nonce, requestId: requestId,
        capturedAt: capturedAt, latitude: '', longitude: '', imageHash: 'x',
      );
      expect(a, b);
    });

    test('offline shape (empty request_id) still signs correctly', () {
      const hash = 'zzz';
      final sig = PhotoService.buildSignature(
        secret: secret, nonceValue: nonce, requestId: '',
        capturedAt: capturedAt, latitude: '', longitude: '', imageHash: hash,
      );
      expect(sig, expected(hash, req: ''));
    });
  });

  group('PhotoUploadResult', () {
    test('defaults count to 1 (legacy single upload)', () {
      const r = PhotoUploadResult(result: 'VALIDATED');
      expect(r.count, 1);
    });
  });

  test('kMaxPhotosPerRequest is 5 (server contract)', () {
    expect(kMaxPhotosPerRequest, 5);
  });
}
