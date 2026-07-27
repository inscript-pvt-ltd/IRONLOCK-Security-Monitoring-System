import 'package:flutter_riverpod/flutter_riverpod.dart';

// ── Photo verification ────────────────────────────────────────────────────

/// Total response window for an online photo request, in seconds. The server
/// gives the guard 90s from when it *raises* the request before it times out
/// (the nonce itself is valid 60s) — see FLUTTER_API_GUIDE "Photo Verification".
/// The countdown is anchored to the request's arrival/issue time, not to when
/// the camera screen opens.
const int kPhotoWindowSeconds = 90;

enum PhotoStatus { idle, capturing, reviewing, uploading, validated, flagged, failed, expired }

class PhotoState {
  const PhotoState({
    this.status = PhotoStatus.idle,
    this.secondsRemaining = kPhotoWindowSeconds,
    this.windowSeconds = kPhotoWindowSeconds,
    this.expireCountdown = 30,
  });
  final PhotoStatus status;
  final int secondsRemaining;
  // Full window length for this request — the progress-bar denominator. Usually
  // [kPhotoWindowSeconds] but parameterised so a server-supplied window can
  // override it without touching the UI.
  final int windowSeconds;
  final int expireCountdown;

  PhotoState copyWith({
    PhotoStatus? status,
    int? secondsRemaining,
    int? windowSeconds,
    int? expireCountdown,
  }) =>
      PhotoState(
        status: status ?? this.status,
        secondsRemaining: secondsRemaining ?? this.secondsRemaining,
        windowSeconds: windowSeconds ?? this.windowSeconds,
        expireCountdown: expireCountdown ?? this.expireCountdown,
      );
}

/// Seconds left in a photo response window, anchored to when the request was
/// issued/arrived rather than when the guard opened the camera. Precedence:
/// the server `issued_at` if it ever sends one, else the app's stamped arrival,
/// else (no anchor at all) the full window. Never negative.
int photoSecondsRemaining({
  DateTime? issuedAt,
  DateTime? receivedAt,
  int windowSeconds = kPhotoWindowSeconds,
  required DateTime now,
}) {
  final anchor = issuedAt ?? receivedAt;
  if (anchor == null) return windowSeconds;
  final remaining = windowSeconds - now.difference(anchor).inSeconds;
  return remaining < 0 ? 0 : remaining;
}

class PhotoNotifier extends Notifier<PhotoState> {
  @override
  PhotoState build() => const PhotoState();

  void tick() {
    final s = state;
    // The response window keeps counting down while the guard lines up the shot
    // (idle), the photo is being taken (capturing), AND while they review it
    // (reviewing) — the whole capture+confirm must fit inside the one window.
    if (s.status == PhotoStatus.idle ||
        s.status == PhotoStatus.capturing ||
        s.status == PhotoStatus.reviewing) {
      final remaining = s.secondsRemaining - 1;
      if (remaining <= 0) {
        state = s.copyWith(status: PhotoStatus.expired, secondsRemaining: 0);
      } else {
        state = s.copyWith(secondsRemaining: remaining);
      }
    } else if (s.status == PhotoStatus.expired) {
      // Count the "expired" banner down for display, but NEVER auto-reset to a
      // fresh idle window — that used to re-open a live camera for a request whose
      // server window is already dead, so any capture then failed NONCE_EXPIRED.
      // The screen closes itself on expiry and resets this provider on dispose.
      final cd = s.expireCountdown - 1;
      state = s.copyWith(expireCountdown: cd < 0 ? 0 : cd);
    }
  }

  /// Clears back to a fresh idle state. Called when a PhotoScreen closes so a
  /// lingering terminal state (expired/failed) can't block the next capture
  /// (the offline scheduler only fires when this provider is idle).
  void reset() => state = const PhotoState();

  /// Open a request's window with a pre-computed remaining time (anchored to the
  /// request's arrival, not now). [remaining] ≤ 0 opens straight into `expired`.
  void startWindow({required int remaining, int windowSeconds = kPhotoWindowSeconds}) {
    state = remaining <= 0
        ? PhotoState(
            status: PhotoStatus.expired,
            secondsRemaining: 0,
            windowSeconds: windowSeconds,
          )
        : PhotoState(
            status: PhotoStatus.idle,
            secondsRemaining: remaining,
            windowSeconds: windowSeconds,
          );
  }

  /// Opens an OFFLINE schedule-triggered capture with a fresh full [kPhotoWindowSeconds]
  /// window, anchored to now. There's no *server* response window offline, but the
  /// overlay must still expire (otherwise it lingers forever if the guard never
  /// captures); the photo is validated server-side by its NTP-anchored timestamp,
  /// not this timer. Expiry closes the screen and records a miss.
  void openScheduled() => state = const PhotoState(
        status: PhotoStatus.idle,
        secondsRemaining: kPhotoWindowSeconds,
        windowSeconds: kPhotoWindowSeconds,
      );

  void startCapture() => state = state.copyWith(status: PhotoStatus.capturing);

  /// Photo taken, awaiting the guard's Retake / Use Photo decision.
  void review() => state = state.copyWith(status: PhotoStatus.reviewing);

  /// Back to the live camera WITHOUT resetting the countdown — the server's
  /// response window is fixed, so a retake spends the same remaining seconds.
  void retake() => state = state.copyWith(status: PhotoStatus.idle);

  void uploading() => state = state.copyWith(status: PhotoStatus.uploading);

  void setResult(PhotoStatus result) => state = state.copyWith(status: result);

  /// Full reset (fresh timer) — used when re-starting after a flagged/failed
  /// attempt. Restores the full window for the current request.
  void tryAgain() => state = PhotoState(
        secondsRemaining: state.windowSeconds,
        windowSeconds: state.windowSeconds,
      );
}

final photoProvider =
    NotifierProvider<PhotoNotifier, PhotoState>(PhotoNotifier.new);

// ── Pending backend-triggered photo request ───────────────────────────────

class PendingPhotoState {
  const PendingPhotoState({
    this.pending = false,
    this.requestId,
    this.nonceValue,
    this.issuedAt,
    this.receivedAt,
    this.responseSeconds,
    this.scheduled = false,
  });
  final bool pending;
  // True for an OFFLINE schedule-triggered capture (Phase 7): no request_id, no
  // server nonce, no countdown — the screen draws a pool nonce at submit and
  // queues. False for the normal online (server-initiated) request.
  final bool scheduled;
  final String? requestId;
  // Server-issued nonce delivered with an online photo request — required to
  // sign the upload. Comes from `GET /shifts/{id}/photos/pending`.
  final String? nonceValue;
  // Server issue time of the request, when the backend sends one (`issued_at`).
  // Null today; honoured for exact server-anchored timing when present.
  final DateTime? issuedAt;
  // When the app first saw the request (foreground push/poll). The countdown
  // falls back to this when there's no `issuedAt`.
  final DateTime? receivedAt;
  // Server-supplied window length, when sent (`response_seconds`). Null → the
  // default [kPhotoWindowSeconds].
  final int? responseSeconds;
}

class PendingPhotoNotifier extends Notifier<PendingPhotoState> {
  @override
  PendingPhotoState build() => const PendingPhotoState();

  void setPending(
    bool val, {
    String? requestId,
    String? nonceValue,
    DateTime? issuedAt,
    DateTime? receivedAt,
    int? responseSeconds,
  }) =>
      state = PendingPhotoState(
        pending: val,
        requestId: requestId,
        nonceValue: nonceValue,
        issuedAt: issuedAt,
        receivedAt: receivedAt,
        responseSeconds: responseSeconds,
      );

  /// Flags an OFFLINE schedule-triggered capture for the home screen to open in
  /// scheduled mode (no request id / server nonce / countdown).
  void setScheduledOffline() =>
      state = const PendingPhotoState(pending: true, scheduled: true);
}

final pendingPhotoProvider =
    NotifierProvider<PendingPhotoNotifier, PendingPhotoState>(PendingPhotoNotifier.new);
