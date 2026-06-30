import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import 'api_client.dart';

/// A supervisor's decision on a photo the guard submitted.
/// `GET /shifts/{id}/photos/reviews` → `data.reviews[]`, newest first.
class PhotoReview {
  const PhotoReview({
    required this.requestId,
    required this.decision,
    this.note,
    this.reviewedAt,
  });

  final String requestId;
  final String decision; // 'APPROVED' | 'REJECTED'
  final String? note;
  final DateTime? reviewedAt;

  bool get approved => decision.toUpperCase() == 'APPROVED';

  factory PhotoReview.fromJson(Map<String, dynamic> json) => PhotoReview(
        requestId: (json['request_id'] ?? '').toString(),
        decision: (json['decision'] ?? '').toString(),
        // A null/empty note is fine (e.g. an approval with no comment).
        note: (json['note'] as String?)?.trim().isNotEmpty == true
            ? (json['note'] as String).trim()
            : null,
        reviewedAt:
            DateTime.tryParse((json['reviewed_at'] ?? '').toString())?.toUtc(),
      );
}

class PhotoReviewService {
  const PhotoReviewService(this._dio);
  final Dio _dio;

  /// Fetches the review outcomes for [shiftId]. Returns an empty list on any
  /// transport error or an unexpected shape (the caller treats "no reviews" and
  /// "couldn't fetch" the same — it just polls again). Skips entries missing a
  /// `request_id` or `decision` so a malformed row can't raise a bogus alert.
  Future<List<PhotoReview>> fetchReviews(String shiftId) async {
    try {
      final res = await _dio.get<Map<String, dynamic>>(
        ApiConfig.shiftPhotosReviews(shiftId),
      );
      final data = res.data?['data'] as Map<String, dynamic>?;
      final reviews = data?['reviews'] as List<dynamic>?;
      if (reviews == null) return const [];
      return reviews
          .whereType<Map<String, dynamic>>()
          .map(PhotoReview.fromJson)
          .where((r) => r.requestId.isNotEmpty && r.decision.isNotEmpty)
          .toList();
    } on DioException {
      return const [];
    }
  }
}

final photoReviewServiceProvider = Provider<PhotoReviewService>(
  (ref) => PhotoReviewService(ref.read(dioProvider)),
);
