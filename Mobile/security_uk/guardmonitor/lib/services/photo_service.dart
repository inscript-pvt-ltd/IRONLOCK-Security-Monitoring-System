import 'dart:convert';
import 'dart:io';
import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import 'api_client.dart';
import 'secure_storage_service.dart';

class PhotoUploadResult {
  const PhotoUploadResult({
    required this.result,
    this.flags = const [],
    this.count = 1,
  });
  final String result; // 'VALIDATED' | 'FLAGGED'
  final List<String> flags;
  // How many images the server stored (1 for a legacy single upload, up to 5).
  final int count;
}

/// Max images a single verification request may answer with (server contract).
const int kMaxPhotosPerRequest = 5;

/// Thrown on a `422 PHOTO_REJECTED` — the photo was **not** stored. [reason]
/// is the server's `error.details.reason` (e.g. `HMAC_INVALID`, `NONCE_EXPIRED`)
/// so the caller can decide whether to retry, re-login, or abandon.
class PhotoRejectedException implements Exception {
  const PhotoRejectedException(this.reason, [this.message]);
  final String reason;
  final String? message;

  /// The signing key may have rotated — a fresh login refreshes `hmac_secret`.
  bool get needsRelogin => reason == 'HMAC_INVALID';

  @override
  String toString() => 'PhotoRejectedException($reason)';
}

class PhotoService {
  const PhotoService(this._dio);
  final Dio _dio;

  /// Uploads 1–5 photos answering one verification request, signed with the
  /// per-guard `hmac_secret` from login. One nonce covers the whole submission.
  ///
  /// [nonceValue] is required — server-issued, either from the online request
  /// (`/photos/pending`) or drawn from the offline pool (`/nonces/prefetch`).
  /// [requestId] is set for online (server-initiated) checks and omitted for
  /// offline (self-initiated) ones.
  ///
  /// **Wire format (server contract):** a single photo uses the legacy
  /// `photo`/`signature` fields (the proven path); 2–5 photos use `photos[]` +
  /// `signatures[]` arrays. Every image shares the same `nonce_value`,
  /// `request_id`, `captured_at`, GPS — only its `signature` (and bytes) differ,
  /// because the signed string's last field is *that image's* `sha256`. It's
  /// all-or-nothing server-side: any bad signature rejects the whole batch.
  ///
  /// Throws [PhotoRejectedException] on a `422 PHOTO_REJECTED`, and
  /// [DioException] on any other transport/HTTP failure.
  Future<PhotoUploadResult> uploadPhotos({
    required List<String> filePaths,
    required String shiftId,
    required String nonceValue,
    String? requestId,
    double? latitude,
    double? longitude,
  }) async {
    assert(filePaths.isNotEmpty && filePaths.length <= kMaxPhotosPerRequest,
        'A verification request takes 1–$kMaxPhotosPerRequest photos');

    final capturedAt = DateTime.now().toUtc().toIso8601String();
    // The exact strings sent in the multipart body, so the signature covers
    // byte-for-byte what the server re-hashes. Empty string for absent fields.
    final reqId = requestId ?? '';
    final lat = latitude?.toString() ?? '';
    final lng = longitude?.toString() ?? '';

    final secret = await SecureStorageService.getHmacSecret();
    if (secret == null) {
      // No key means we can't produce a verifiable signature — surface it as a
      // rejection so the UI can prompt a re-login rather than firing a doomed
      // upload the server will reject anyway.
      throw const PhotoRejectedException('HMAC_INVALID', 'Missing signing key');
    }

    // Sign each image (shared fields + that image's own hash) and build its
    // multipart file in the same order.
    final files = <MapEntry<String, MultipartFile>>[];
    final signatures = <String>[];
    final single = filePaths.length == 1;
    for (var i = 0; i < filePaths.length; i++) {
      final path = filePaths[i];
      final imageHash = sha256.convert(await File(path).readAsBytes()).toString();
      signatures.add(buildSignature(
        secret: secret,
        nonceValue: nonceValue,
        requestId: reqId,
        capturedAt: capturedAt,
        latitude: lat,
        longitude: lng,
        imageHash: imageHash,
      ));
      files.add(MapEntry(
        single ? 'photo' : 'photos[]',
        await MultipartFile.fromFile(path, filename: 'guard_photo_$i.jpg'),
      ));
    }

    // Shared, single-instance fields (sent once for the whole batch).
    final formData = FormData();
    formData.fields.addAll([
      MapEntry('nonce_value', nonceValue),
      MapEntry('captured_at', capturedAt),
      MapEntry('latitude', lat),
      MapEntry('longitude', lng),
      // request_id only on an online (server-initiated) check.
      if (requestId != null) MapEntry('request_id', requestId),
      // Single → `signature`; multi → repeated `signatures[]` in photo order.
      if (single)
        MapEntry('signature', signatures.first)
      else
        ...signatures.map((s) => MapEntry('signatures[]', s)),
    ]);
    formData.files.addAll(files);

    try {
      final response = await _dio.post<Map<String, dynamic>>(
        ApiConfig.shiftPhotos(shiftId),
        data: formData,
        options: Options(
          contentType: 'multipart/form-data',
          receiveTimeout: const Duration(seconds: 60),
        ),
      );

      final apiResponse = ApiResponse.fromJson(response.data!, (data) {
        final map = data as Map<String, dynamic>;
        return PhotoUploadResult(
          result: map['result'] as String,
          flags: (map['flags'] as List?)?.cast<String>() ?? const [],
          count: (map['count'] as num?)?.toInt() ?? filePaths.length,
        );
      });
      return apiResponse.data!;
    } on DioException catch (e) {
      // 422 PHOTO_REJECTED → translate to a typed exception with the reason.
      final data = e.response?.data;
      if (e.response?.statusCode == 422 && data is Map<String, dynamic>) {
        final error = data['error'] as Map<String, dynamic>?;
        if (error?['code'] == 'PHOTO_REJECTED') {
          final details = error?['details'] as Map<String, dynamic>?;
          throw PhotoRejectedException(
            details?['reason'] as String? ?? 'UNKNOWN',
            error?['message'] as String?,
          );
        }
      }
      rethrow;
    }
  }

  /// Uploads a photo batch that was captured + signed **offline** against a
  /// pre-fetched pool nonce, used by the Phase 7 flush engine. Everything is sent
  /// **verbatim from the queue** — the stored [signatures], [capturedAt], GPS and
  /// the [ntpReference]/[elapsedSeconds] time proof — because the signature was
  /// computed over those exact strings at capture; recomputing here would break
  /// it. No `request_id` (offline = self-initiated, pool nonce).
  ///
  /// Returns the [PhotoUploadResult] on success; throws [PhotoRejectedException]
  /// on a `422 PHOTO_REJECTED` (so the flusher treats `NONCE_ALREADY_USED` as
  /// success and other reasons as terminal) and rethrows other [DioException]s
  /// for the retry table.
  Future<PhotoUploadResult> submitOfflinePhotos({
    required String shiftId,
    required String nonceValue,
    required List<String> filePaths,
    required List<String> signatures,
    required String capturedAt,
    String? ntpReference,
    double elapsedSeconds = 0,
    String latitude = '',
    String longitude = '',
  }) async {
    assert(filePaths.length == signatures.length && filePaths.isNotEmpty);
    final single = filePaths.length == 1;

    final formData = FormData();
    formData.fields.addAll([
      MapEntry('nonce_value', nonceValue),
      MapEntry('captured_at', capturedAt),
      MapEntry('latitude', latitude),
      MapEntry('longitude', longitude),
      MapEntry('elapsed_seconds', elapsedSeconds.toString()),
      if (ntpReference != null) MapEntry('ntp_reference', ntpReference),
      if (single)
        MapEntry('signature', signatures.first)
      else
        ...signatures.map((s) => MapEntry('signatures[]', s)),
    ]);
    for (var i = 0; i < filePaths.length; i++) {
      formData.files.add(MapEntry(
        single ? 'photo' : 'photos[]',
        await MultipartFile.fromFile(filePaths[i], filename: 'guard_photo_$i.jpg'),
      ));
    }

    try {
      final response = await _dio.post<Map<String, dynamic>>(
        ApiConfig.shiftPhotos(shiftId),
        data: formData,
        options: Options(
          contentType: 'multipart/form-data',
          receiveTimeout: const Duration(seconds: 60),
        ),
      );
      return ApiResponse.fromJson(response.data!, (data) {
        final map = data as Map<String, dynamic>;
        return PhotoUploadResult(
          result: map['result'] as String? ?? 'VALIDATED',
          flags: (map['flags'] as List?)?.cast<String>() ?? const [],
          count: (map['count'] as num?)?.toInt() ?? filePaths.length,
        );
      }).data!;
    } on DioException catch (e) {
      final data = e.response?.data;
      if (e.response?.statusCode == 422 && data is Map<String, dynamic>) {
        final error = data['error'] as Map<String, dynamic>?;
        if (error?['code'] == 'PHOTO_REJECTED') {
          final details = error?['details'] as Map<String, dynamic>?;
          throw PhotoRejectedException(
            details?['reason'] as String? ?? 'UNKNOWN',
            error?['message'] as String?,
          );
        }
      }
      rethrow;
    }
  }

  /// HMAC-SHA256 over the six fields joined by `\n`, in this exact order, keyed
  /// with the login `hmac_secret`. The server re-builds the same string and
  /// must get the same digest — so the fields here must equal the multipart
  /// fields byte-for-byte. Public + pure for testability: in a multi-photo batch
  /// every image shares all fields except `imageHash`, so this is what makes each
  /// image's signature differ.
  static String buildSignature({
    required String secret,
    required String nonceValue,
    required String requestId,
    required String capturedAt,
    required String latitude,
    required String longitude,
    required String imageHash,
  }) {
    final message = [
      nonceValue,
      requestId,
      capturedAt,
      latitude,
      longitude,
      imageHash,
    ].join('\n');
    return Hmac(sha256, utf8.encode(secret)).convert(utf8.encode(message)).toString();
  }
}

final photoServiceProvider = Provider<PhotoService>(
  (ref) => PhotoService(ref.read(dioProvider)),
);
