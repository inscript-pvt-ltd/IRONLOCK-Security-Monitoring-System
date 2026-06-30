import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:ntp/ntp.dart';

/// The trusted time proof attached to a photo capture. The server rebuilds the
/// capture instant from these — never from the device wall clock.
class PhotoTimeProof {
  const PhotoTimeProof({
    required this.capturedAt,
    this.ntpReference,
    this.elapsedSeconds = 0,
  });

  /// NTP-true capture instant (ISO-8601 UTC), or null when no NTP anchor was
  /// ever obtained (device has been offline since launch). Null → the server
  /// flags `NTP_UNAVAILABLE` / `DELAYED_UPLOAD` rather than trusting us.
  final String? ntpReference;

  /// Monotonic seconds from [ntpReference] to the shutter. We project the anchor
  /// forward at capture time (see [TimeAnchorService.capture]), so this is 0 —
  /// `ntpReference` already *is* the shutter instant. Kept in the contract shape
  /// (`reconstructed = ntp_reference + elapsed_seconds`) for the server.
  final double elapsedSeconds;

  /// Best-known capture instant (UTC) for the diagnostic `captured_at` field —
  /// the projected NTP time when anchored, else the device wall clock.
  final DateTime capturedAt;
}

/// Provides a tamper-resistant capture time for photos.
///
/// While online it captures an **NTP anchor** (true time) paired with a
/// monotonic [Stopwatch]. At capture it projects the anchor forward by the
/// stopwatch's elapsed time — immune to the user changing the device clock,
/// because the stopwatch is monotonic and the anchor came from NTP. This keeps
/// `ntp_reference` aligned with the photo's EXIF time for an honest device (the
/// server flags a >30s NTP↔EXIF gap as `CLOCK_MANIPULATION_SUSPECTED`), while a
/// manipulated wall clock can't move our reference.
class TimeAnchorService {
  DateTime? _ntpAtSync; // NTP-true time at the last successful sync (UTC)
  Stopwatch? _monotonic; // started at that sync
  DateTime? _syncedAtWall; // device wall time of the sync, for staleness check

  /// Default SNTP host (backend doesn't prescribe one; Google's is leap-smeared
  /// and stable).
  static const String kNtpHost = 'time.google.com';

  bool get hasAnchor => _ntpAtSync != null && _monotonic != null;

  /// Re-syncs the NTP anchor. Online-only and best-effort: on failure (offline,
  /// UDP blocked, timeout) the previous anchor is kept. Call opportunistically
  /// while online — at shift start and around online photo captures.
  Future<void> sync({
    String host = kNtpHost,
    Duration timeout = const Duration(seconds: 5),
  }) async {
    try {
      final offsetMs =
          await NTP.getNtpOffset(lookUpAddress: host).timeout(timeout);
      _ntpAtSync = DateTime.now().toUtc().add(Duration(milliseconds: offsetMs));
      _monotonic = Stopwatch()..start();
      _syncedAtWall = DateTime.now();
    } catch (_) {
      // Keep whatever anchor we already have (may be none).
    }
  }

  /// Syncs only if there's no anchor yet or the current one is older than
  /// [maxAge]. Cheap to call before every online capture.
  Future<void> ensureFresh({
    Duration maxAge = const Duration(minutes: 10),
    String host = kNtpHost,
  }) async {
    final synced = _syncedAtWall;
    if (hasAnchor &&
        synced != null &&
        DateTime.now().difference(synced) < maxAge) {
      return;
    }
    await sync(host: host);
  }

  /// The time proof for a capture happening *now*. Projects the NTP anchor to
  /// this instant via the monotonic clock; falls back to the device wall clock
  /// with a null `ntp_reference` when no anchor exists.
  PhotoTimeProof capture() {
    final anchor = _ntpAtSync;
    final mono = _monotonic;
    if (anchor != null && mono != null) {
      final projected = anchor.add(mono.elapsed).toUtc();
      return PhotoTimeProof(
        capturedAt: projected,
        ntpReference: projected.toIso8601String(),
        elapsedSeconds: 0,
      );
    }
    return PhotoTimeProof(capturedAt: DateTime.now().toUtc());
  }
}

final timeAnchorServiceProvider =
    Provider<TimeAnchorService>((ref) => TimeAnchorService());
