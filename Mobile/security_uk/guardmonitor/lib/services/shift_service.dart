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

  /// Returns the server's `actual_start` timestamp plus the optional
  /// `wakefulness` provisioning block (TOTP seed + schedule).
  /// The start response is a partial payload ({id, status, actual_start,
  /// can_end, wakefulness?}) — we only extract what changed and merge in the
  /// notifier. A 200 means the shift started on the server, so this NEVER
  /// throws on the response body: any missing/oddly-typed field just yields a
  /// null value (the caller falls back to device time). Only DioException (real
  /// HTTP errors like 409 SHIFT_NOT_STARTABLE) propagates.
  /// Handles both {data:{shift:{...}}} and {data:{...}} response shapes.
  Future<({DateTime? actualStart, Map<String, dynamic>? wakefulness})> startShift(
      String shiftId) async {
    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftStart(shiftId),
    );
    if (kDebugMode) {
      debugPrint('[shift] POST /shifts/$shiftId/start '
          '→ ${response.statusCode} ${response.data}');
    }
    final shift = _extractShift(response.data);
    // The wakefulness block may sit on the shift object or alongside it in data.
    final data = response.data?['data'];
    final wakefulness = (shift?['wakefulness'] ??
        (data is Map<String, dynamic> ? data['wakefulness'] : null));
    return (
      actualStart: _parseTime(shift?['actual_start']),
      wakefulness: wakefulness is Map<String, dynamic> ? wakefulness : null,
    );
  }

  /// Returns the server's `actual_start`, `actual_end`, `duration_hours`, and `end_type`.
  /// The end response is also a partial payload — same merge strategy as start,
  /// and the same "never throw on a 200 body" guarantee.
  /// Handles both {data:{shift:{...}}} and {data:{...}} response shapes.
  Future<({DateTime? actualStart, DateTime? actualEnd, double? durationHours, String? endType})>
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
      endType: shift?['end_type'] as String?,
    );
  }

  /// Submits an early-end *request* for supervisor approval — it does NOT end
  /// the shift. The shift stays `active`; the app then polls `GET /shifts/current`
  /// for the `early_end_request.status` to flip to `approved`/`rejected`.
  /// Throws [DioException] on a real HTTP rejection (e.g. a duplicate pending
  /// request) so the caller can surface it.
  Future<void> requestEarlyEnd(
    String shiftId, {
    required String reason,
    required String note,
  }) async {
    final body = <String, dynamic>{'reason': reason};
    final trimmedNote = note.trim();
    if (trimmedNote.isNotEmpty) body['note'] = trimmedNote;

    final response = await _dio.post<Map<String, dynamic>>(
      ApiConfig.shiftEarlyEndRequest(shiftId),
      data: body,
    );
    if (kDebugMode) {
      debugPrint('[shift] POST /shifts/$shiftId/early-end-request '
          '→ ${response.statusCode} ${response.data}');
    }
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
