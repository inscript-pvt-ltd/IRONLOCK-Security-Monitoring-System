import 'package:dio/dio.dart';

class ApiResponse<T> {
  const ApiResponse({
    required this.success,
    this.data,
  });

  final bool success;
  final T? data;

  factory ApiResponse.fromJson(
    Map<String, dynamic> json,
    T Function(dynamic)? fromData,
  ) {
    return ApiResponse(
      success: json['success'] as bool? ?? false,
      data: fromData != null && json['data'] != null
          ? fromData(json['data'])
          : null,
    );
  }
}

class ApiError {
  const ApiError({
    required this.code,
    required this.message,
    this.details,
  });

  final String code;
  final String message;
  final Map<String, dynamic>? details;

  factory ApiError.fromJson(Map<String, dynamic> json) {
    return ApiError(
      code: json['code'] as String? ?? 'SERVER_ERROR',
      message: json['message'] as String? ?? 'Something went wrong.',
      details: json['details'] as Map<String, dynamic>?,
    );
  }

  /// Builds an [ApiError] from any [DioException], falling back to a
  /// connectivity/timeout message when the server never responded with the
  /// `{success:false,error:{...}}` envelope at all.
  factory ApiError.fromDioException(DioException exception) {
    final responseData = exception.response?.data;
    if (responseData is Map<String, dynamic> && responseData['error'] is Map) {
      return ApiError.fromJson(responseData['error'] as Map<String, dynamic>);
    }
    return const ApiError(
      code: 'SERVER_ERROR',
      message: 'Could not reach the server. Check your connection and try again.',
    );
  }
}
