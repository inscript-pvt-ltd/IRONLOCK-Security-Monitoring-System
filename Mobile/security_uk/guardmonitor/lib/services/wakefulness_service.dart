import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import 'api_client.dart';

class WakefulnessService {
  const WakefulnessService(this._dio);
  final Dio _dio;

  /// Returns `true` if the code was correct (`PASSED`), `false` otherwise.
  Future<bool> respond(String checkId, String code) async {
    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.wakefulnessRespond(checkId),
      data: {
        'code': code,
        'responded_at': DateTime.now().toUtc().toIso8601String(),
      },
    );
    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => (data as Map<String, dynamic>)['result'] as String,
    );
    return apiResponse.data == 'PASSED';
  }
}

final wakefulnessServiceProvider = Provider<WakefulnessService>(
  (ref) => WakefulnessService(ref.read(dioProvider)),
);
