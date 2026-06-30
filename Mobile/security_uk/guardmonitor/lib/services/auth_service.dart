import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../config/api_config.dart';
import '../models/api_response.dart';
import '../models/auth_token_model.dart';
import '../models/guard_profile_model.dart';
import 'api_client.dart';
import 'device_info_service.dart';

class AuthService {
  const AuthService(this._dio);
  final Dio _dio;

  Future<AuthTokenModel> login(String identifier, String password) async {
    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.login,
      data: {
        'identifier': identifier.trim(),
        'password': password,
        'device': await DeviceInfoService.toJson(),
      },
    );

    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => AuthTokenModel.fromJson(data as Map<String, dynamic>),
    );

    return apiResponse.data!;
  }

  /// Redeems a Shift Access Link (SSO) one-time token. Public endpoint (no auth
  /// header). Success returns the **same payload as login** (tokens + hmac_secret
  /// + guard) and the guard is already `checked_in` server-side. Failures surface
  /// as the usual `{success:false,error:{code,…}}` envelope — the caller maps the
  /// SHIFT_ACCESS_* / LOGIN_WINDOW_CLOSED codes to UI.
  Future<AuthTokenModel> redeemShiftAccess(String token) async {
    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftAccess,
      data: {
        'token': token,
        'device': await DeviceInfoService.toJson(),
      },
    );

    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => AuthTokenModel.fromJson(data as Map<String, dynamic>),
    );

    return apiResponse.data!;
  }

  Future<void> logout() async {
    await _dio.post<void>(ApiConfig.logout);
  }

  Future<GuardProfileModel> getProfile() async {
    final response = await _dio.get<Map<String, dynamic>>(ApiConfig.me);
    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => GuardProfileModel.fromJson(
        (data as Map<String, dynamic>)['guard'] as Map<String, dynamic>,
      ),
    );
    return apiResponse.data!;
  }
}

final authServiceProvider = Provider<AuthService>(
  (ref) => AuthService(ref.read(dioProvider)),
);
