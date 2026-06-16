import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import 'api_client.dart';

class AlertsService {
  const AlertsService(this._dio);
  final Dio _dio;

  Future<List<Map<String, dynamic>>> fetchAlerts() async {
    final response =
        await _dio.get<Map<String, dynamic>>(ApiConfig.alerts);
    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => (data as List).cast<Map<String, dynamic>>(),
    );
    return apiResponse.data ?? [];
  }

  Future<void> dismissAlert(String alertId) async {
    await _dio.post<void>(ApiConfig.alertDismiss(alertId));
  }
}

final alertsServiceProvider = Provider<AlertsService>(
  (ref) => AlertsService(ref.read(dioProvider)),
);
