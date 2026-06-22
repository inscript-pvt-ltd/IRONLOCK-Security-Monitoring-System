import 'package:flutter_riverpod/flutter_riverpod.dart';

// ── Photo verification ────────────────────────────────────────────────────

enum PhotoStatus { idle, capturing, uploading, validated, flagged, failed, expired }

class PhotoState {
  const PhotoState({
    this.status = PhotoStatus.idle,
    this.secondsRemaining = 78,
    this.expireCountdown = 30,
  });
  final PhotoStatus status;
  final int secondsRemaining;
  final int expireCountdown;

  PhotoState copyWith({
    PhotoStatus? status,
    int? secondsRemaining,
    int? expireCountdown,
  }) =>
      PhotoState(
        status: status ?? this.status,
        secondsRemaining: secondsRemaining ?? this.secondsRemaining,
        expireCountdown: expireCountdown ?? this.expireCountdown,
      );
}

class PhotoNotifier extends Notifier<PhotoState> {
  @override
  PhotoState build() => const PhotoState();

  void tick() {
    final s = state;
    if (s.status == PhotoStatus.idle || s.status == PhotoStatus.capturing) {
      final remaining = s.secondsRemaining - 1;
      if (remaining <= 0) {
        state = s.copyWith(status: PhotoStatus.expired, secondsRemaining: 0);
      } else {
        state = s.copyWith(secondsRemaining: remaining);
      }
    } else if (s.status == PhotoStatus.expired) {
      final cd = s.expireCountdown - 1;
      if (cd <= 0) {
        state = const PhotoState();
      } else {
        state = s.copyWith(expireCountdown: cd);
      }
    }
  }

  void capture() => state = state.copyWith(status: PhotoStatus.uploading);

  void setResult(PhotoStatus result) => state = state.copyWith(status: result);

  void tryAgain() => state = const PhotoState();
}

final photoProvider =
    NotifierProvider<PhotoNotifier, PhotoState>(PhotoNotifier.new);

// ── Pending backend-triggered photo request ───────────────────────────────

class PendingPhotoState {
  const PendingPhotoState({this.pending = false, this.requestId});
  final bool pending;
  final String? requestId;
}

class PendingPhotoNotifier extends Notifier<PendingPhotoState> {
  @override
  PendingPhotoState build() => const PendingPhotoState();

  void setPending(bool val, {String? requestId}) =>
      state = PendingPhotoState(pending: val, requestId: requestId);
}

final pendingPhotoProvider =
    NotifierProvider<PendingPhotoNotifier, PendingPhotoState>(PendingPhotoNotifier.new);
