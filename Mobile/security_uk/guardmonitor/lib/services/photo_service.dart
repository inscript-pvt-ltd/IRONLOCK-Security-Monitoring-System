import 'dart:convert';
import 'package:crypto/crypto.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import 'api_client.dart';

class PhotoUploadResult {
  const PhotoUploadResult({required this.result});
  final String result; // 'VALIDATED' | 'FLAGGED'
}

class PhotoService {
  const PhotoService(this._dio);
  final Dio _dio;

  Future<PhotoUploadResult> uploadPhoto({
    required String filePath,
    required String shiftId,
    required String requestId,
    double? latitude,
    double? longitude,
    String? nonce,
  }) async {
    final capturedAt = DateTime.now().toUtc().toIso8601String();
    final signature = nonce != null ? _sign(nonce, shiftId, capturedAt) : null;

    final formData = FormData.fromMap({
      'photo': await MultipartFile.fromFile(filePath, filename: 'guard_photo.jpg'),
      'request_id': requestId,
      'captured_at': capturedAt,
      'latitude': ?latitude,
      'longitude': ?longitude,
      // Extra anti-replay fields kept alongside the spec's required fields
      // per project decision — the backend may ignore them for now.
      'nonce': ?nonce,
      'signature': ?signature,
    });

    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftPhotos(shiftId),
      data: formData,
      options: Options(
        contentType: 'multipart/form-data',
        receiveTimeout: const Duration(seconds: 60),
      ),
    );

    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => PhotoUploadResult(result: (data as Map<String, dynamic>)['result'] as String),
    );
    return apiResponse.data!;
  }

  static String _sign(String nonce, String shiftId, String capturedAt) {
    final message = '$nonce:$shiftId:$capturedAt';
    final key = utf8.encode(ApiConfig.photoHmacSecret);
    final bytes = utf8.encode(message);
    return Hmac(sha256, key).convert(bytes).toString();
  }
}

final photoServiceProvider = Provider<PhotoService>(
  (ref) => PhotoService(ref.read(dioProvider)),
);
