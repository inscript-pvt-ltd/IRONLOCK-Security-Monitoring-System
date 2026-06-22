import 'package:dio/dio.dart';
import 'package:flutter/foundation.dart';
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
    if (kDebugMode) debugPrint('[shift] GET /shifts/current → ${response.data}');
    final apiResponse = ApiResponse.fromJson(
      response.data!,
      (data) => (data as Map<String, dynamic>)['shift'],
    );
    final shiftJson = apiResponse.data as Map<String, dynamic>?;
    return shiftJson != null ? CurrentShiftModel.fromJson(shiftJson) : null;
  }

  /// Returns the server's `actual_start` timestamp.
  /// The start response is a partial payload ({id, status, actual_start,
  /// can_end}) — we only extract what changed and merge in the notifier.
  /// A 200 means the shift started on the server, so this NEVER throws on the
  /// response body: any missing/oddly-typed field just yields a null timestamp
  /// (the caller falls back to device time). Only DioException (real HTTP
  /// errors like 409 SHIFT_NOT_STARTABLE) propagates.
  /// Handles both {data:{shift:{...}}} and {data:{...}} response shapes.
  Future<DateTime?> startShift(String shiftId) async {
    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftStart(shiftId),
    );
    if (kDebugMode) {
      debugPrint('[shift] POST /shifts/$shiftId/start '
          '→ ${response.statusCode} ${response.data}');
    }
    final shift = _extractShift(response.data);
    return _parseTime(shift?['actual_start']);
  }

  /// Returns the server's `actual_start`, `actual_end`, and `duration_hours`.
  /// The end response is also a partial payload — same merge strategy as start,
  /// and the same "never throw on a 200 body" guarantee.
  /// Handles both {data:{shift:{...}}} and {data:{...}} response shapes.
  Future<({DateTime? actualStart, DateTime? actualEnd, double? durationHours})>
      endShift(
    String shiftId, {
    bool endedEarly = false,
    String? reason,
    String? note,
  }) async {
    final body = <String, dynamic>{'ended_early': endedEarly};
    if (reason != null) body['reason'] = reason;
    final trimmedNote = note?.trim();
    if (trimmedNote != null && trimmedNote.isNotEmpty) body['note'] = trimmedNote;

    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftEnd(shiftId),
      data: body,
    );
    final shift = _extractShift(response.data);
    return (
      actualStart: _parseTime(shift?['actual_start']),
      actualEnd: _parseTime(shift?['actual_end']),
      durationHours: (shift?['duration_hours'] as num?)?.toDouble(),
    );
  }

  /// Pulls the shift map out of either {data:{shift:{...}}}, {data:{...}},
  /// or a bare {...} response, tolerating any unexpected shape.
  Map<String, dynamic>? _extractShift(Map<String, dynamic>? body) {
    if (body == null) return null;
    final data = body['data'];
    if (data is Map<String, dynamic>) {
      final nested = data['shift'];
      if (nested is Map<String, dynamic>) return nested;
      return data;
    }
    return body;
  }

  /// Parses an ISO-8601 timestamp defensively — returns null for anything
  /// that isn't a parseable string (epoch ints, nulls, malformed values),
  /// rather than throwing.
  DateTime? _parseTime(dynamic value) {
    if (value is! String) return null;
    return DateTime.tryParse(value)?.toLocal();
  }
}

final shiftServiceProvider = Provider<ShiftService>(
  (ref) => ShiftService(ref.read(dioProvider)),
);
