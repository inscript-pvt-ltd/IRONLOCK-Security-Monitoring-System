import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/notification_service.dart';
import '../services/photo_review_service.dart';
import '../services/secure_storage_service.dart';
import 'alerts_provider.dart';

/// Surfaces supervisor photo-review outcomes (APPROVED/REJECTED + note) to the
/// guard. Both delivery paths converge here:
/// - **push** `PHOTO_REVIEWED` ([ingestPush]) — foreground; the OS already drew
///   the tray banner, so we only add the in-app alert.
/// - **poll** `GET /shifts/{id}/photos/reviews` ([pollAndIngest]) — the reliable
///   fallback (works on iOS without APNs); fires a local tray notification when
///   push isn't the delivery channel.
///
/// Each review is surfaced **once** — deduped by `request_id`, persisted so it
/// survives relaunches and can't double-fire across push + poll.
class PhotoReviewNotifier extends Notifier<void> {
  final Set<String> _seen = {};
  bool _restored = false;

  @override
  void build() {
    _restore();
  }

  Future<void> _restore() async {
    if (_restored) return;
    _restored = true;
    _seen.addAll(await SecureStorageService.getSeenReviewIds());
  }

  /// Poll path: fetch the shift's reviews and surface any not seen yet.
  /// [pushDelivering] suppresses our local notification when push already drew
  /// the OS banner (avoids a duplicate); pass `PushMessaging.isDelivering`.
  Future<void> pollAndIngest(String shiftId,
      {required bool pushDelivering}) async {
    await _restore();
    final reviews =
        await ref.read(photoReviewServiceProvider).fetchReviews(shiftId);
    for (final r in reviews) {
      await _ingest(r.requestId, r.decision, r.note, fireTray: !pushDelivering);
    }
  }

  /// Push path: a PHOTO_REVIEWED arrived in the foreground. The OS handles the
  /// tray banner, so we only add the in-app alert (no local notification).
  Future<void> ingestPush({
    required String requestId,
    required String decision,
    String? note,
  }) async {
    await _restore();
    await _ingest(requestId, decision, note, fireTray: false);
  }

  Future<void> _ingest(String requestId, String decision, String? note,
      {required bool fireTray}) async {
    if (requestId.isEmpty || _seen.contains(requestId)) return;
    _seen.add(requestId);
    await SecureStorageService.addSeenReviewId(requestId);

    final approved = decision.toUpperCase() == 'APPROVED';
    final description = approved
        ? 'Your verification photo was approved.'
        : (note != null && note.isNotEmpty)
            ? 'Rejected: $note'
            : 'Your verification photo was rejected.';

    // In-app alert (always) — lands in the alerts list with the note.
    ref.read(alertsProvider.notifier).prepend(AppAlert(
          id: 'review-$requestId',
          severity: approved ? AlertSeverity.notice : AlertSeverity.urgent,
          title: approved ? 'Photo approved' : 'Photo rejected',
          description: description,
          time: 'just now',
        ));

    if (fireTray) {
      await NotificationService.showPhotoReview(
        decision: decision,
        note: note,
        requestId: requestId,
      );
    }
  }
}

final photoReviewProvider =
    NotifierProvider<PhotoReviewNotifier, void>(PhotoReviewNotifier.new);
