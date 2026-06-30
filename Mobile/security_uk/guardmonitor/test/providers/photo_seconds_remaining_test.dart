import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/providers/photo_provider.dart';

/// Pins the server-anchored photo countdown: the response window starts when the
/// request was issued/arrived, NOT when the guard opens the camera. So a tap
/// 40s after the request landed must show ~50s left, and a tap after the window
/// has already passed must open expired (0).
void main() {
  final now = DateTime.utc(2026, 6, 25, 12, 0, 0);

  group('photoSecondsRemaining', () {
    test('no anchor at all → full window (legacy fallback)', () {
      expect(
        photoSecondsRemaining(now: now, windowSeconds: 90),
        90,
      );
    });

    test('anchors to issuedAt — elapsed time is already spent', () {
      final remaining = photoSecondsRemaining(
        issuedAt: now.subtract(const Duration(seconds: 40)),
        windowSeconds: 90,
        now: now,
      );
      expect(remaining, 50);
    });

    test('falls back to receivedAt when no issuedAt', () {
      final remaining = photoSecondsRemaining(
        receivedAt: now.subtract(const Duration(seconds: 30)),
        windowSeconds: 90,
        now: now,
      );
      expect(remaining, 60);
    });

    test('issuedAt takes precedence over receivedAt', () {
      final remaining = photoSecondsRemaining(
        issuedAt: now.subtract(const Duration(seconds: 80)),
        receivedAt: now.subtract(const Duration(seconds: 5)),
        windowSeconds: 90,
        now: now,
      );
      expect(remaining, 10);
    });

    test('already past the window → 0 (opens expired), never negative', () {
      final remaining = photoSecondsRemaining(
        issuedAt: now.subtract(const Duration(seconds: 200)),
        windowSeconds: 90,
        now: now,
      );
      expect(remaining, 0);
    });

    test('default window is the 90s contract value', () {
      expect(kPhotoWindowSeconds, 90);
      expect(photoSecondsRemaining(now: now), 90);
    });
  });
}
