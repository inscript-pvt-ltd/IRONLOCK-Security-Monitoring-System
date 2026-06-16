import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import '../models/current_shift_model.dart';
import 'api_client.dart';

class ShiftService {
  const ShiftService(this._dio);
  final Dio _dio;

  Future<CurrentShiftModel?> fetchCurrent() async {
    final response = await _dio.get<Map<String, dynamic>>(ApiConfig.shiftCurrent);
    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => (data as Map<String, dynamic>)['shift'],
    );
    final shiftJson = apiResponse.data as Map<String, dynamic>?;
    return shiftJson != null ? CurrentShiftModel.fromJson(shiftJson) : null;
  }

  Future<CurrentShiftModel> startShift(
    String shiftId, {
    double? latitude,
    double? longitude,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftStart(shiftId),
      data: {
        'latitude': ?latitude,
        'longitude': ?longitude,
      },
    );
    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => CurrentShiftModel.fromJson(
        (data as Map<String, dynamic>)['shift'] as Map<String, dynamic>,
      ),
    );
    return apiResponse.data!;
  }

  Future<CurrentShiftModel> endShift(
    String shiftId, {
    double? latitude,
    double? longitude,
  }) async {
    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftEnd(shiftId),
      data: {
        'latitude': ?latitude,
        'longitude': ?longitude,
      },
    );
    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => CurrentShiftModel.fromJson(
        (data as Map<String, dynamic>)['shift'] as Map<String, dynamic>,
      ),
    );
    return apiResponse.data!;
  }
}

final shiftServiceProvider = Provider<ShiftService>(
  (ref) => ShiftService(ref.read(dioProvider)),
);
