import '../utils/server_time.dart';

class ShiftSiteModel {
  const ShiftSiteModel({
    required this.id,
    required this.name,
    this.gracePeriodMinutes,
  });

  final String id;
  final String name;
  final int? gracePeriodMinutes;

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        if (gracePeriodMinutes != null) 'grace_period_minutes': gracePeriodMinutes,
      };

  factory ShiftSiteModel.fromJson(Map<String, dynamic> json) {
    return ShiftSiteModel(
      id: json['id'] as String,
      name: json['name'] as String,
      gracePeriodMinutes: json['grace_period_minutes'] as int?,
    );
  }
}

class ShiftGeofenceModel {
  const ShiftGeofenceModel({
    required this.id,
    required this.name,
    required this.coordinates,
  });

  final String id;
  final String name;
  final List<List<double>> coordinates;

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'coordinates': coordinates,
      };

  factory ShiftGeofenceModel.fromJson(Map<String, dynamic> json) {
    return ShiftGeofenceModel(
      id: json['id'] as String,
      name: json['name'] as String,
      coordinates: (json['coordinates'] as List)
          .map((pair) => (pair as List).map((v) => (v as num).toDouble()).toList())
          .toList(),
    );
  }
}

class CurrentShiftModel {
  const CurrentShiftModel({
    required this.id,
    required this.status,
    required this.scheduledStart,
    required this.scheduledEnd,
    required this.canStart,
    required this.canEnd,
    this.canRequestEarlyEnd = false,
    this.reference,
    this.actualStart,
    this.actualEnd,
    this.role,
    this.notes,
    this.site,
    this.geofence,
    this.durationHours,
    this.endType,
    this.earlyEndStatus,
    this.earlyEndReason,
    this.earlyEndNote,
  });

  final String id;
  final String? reference; // human-readable display code (e.g. "SH-2847"); display-only, never a key
  final String status; // scheduled | checked_in | active | completed | cancelled | missed
  final DateTime scheduledStart;
  final DateTime scheduledEnd;
  final DateTime? actualStart;
  final DateTime? actualEnd;
  final bool canStart;
  final bool canEnd;
  /// Server flag: guard may submit an early-end request right now.
  /// Incorporates timing + existing request state so device clock isn't needed.
  final bool canRequestEarlyEnd;
  final String? role;
  final String? notes;
  final ShiftSiteModel? site;
  final ShiftGeofenceModel? geofence;
  final double? durationHours;
  /// How the shift closed: 'guard' (normal), 'early' (approved early end),
  /// 'auto' (server force-closed). Only present on completed shifts.
  final String? endType;

  /// Early-end approval state, mirrored from the server's `early_end_request`
  /// object on `GET /shifts/current`. `null` = no request outstanding;
  /// otherwise `pending` (awaiting supervisor), `approved` (guard may now end),
  /// or `rejected` (declined — guard keeps working / may request again).
  final String? earlyEndStatus;
  final String? earlyEndReason;
  final String? earlyEndNote;

  bool get earlyEndPending => earlyEndStatus == 'pending';
  bool get earlyEndApproved => earlyEndStatus == 'approved';
  bool get earlyEndRejected => earlyEndStatus == 'rejected';

  /// Display label for the shift. Prefers the server's human-readable
  /// `reference` (e.g. "SH-2847"), prefixed with "#"; falls back to a short
  /// label derived from the UUID when the backend doesn't send one.
  String get displayRef => reference != null
      ? '#$reference'
      : '#SH-${id.replaceAll('-', '').substring(0, 6).toUpperCase()}';

  /// Serialises the shift for on-device persistence, so a cold start after a
  /// swipe-kill can show the full shift card (site name, schedule, overdue
  /// banner) WITHOUT waiting on `GET /shifts/current` — which can be null for an
  /// active shift, or simply unreachable offline. Emits datetimes as UTC `Z`
  /// strings so [fromJson] (`parseServerUtc(...).toLocal()`) round-trips them to
  /// the exact same local instant. Keys mirror the server payload so the one
  /// [fromJson] reads both.
  Map<String, dynamic> toJson() => {
        'id': id,
        if (reference != null) 'reference': reference,
        'status': status,
        'scheduled_start': scheduledStart.toUtc().toIso8601String(),
        'scheduled_end': scheduledEnd.toUtc().toIso8601String(),
        if (actualStart != null)
          'actual_start': actualStart!.toUtc().toIso8601String(),
        if (actualEnd != null) 'actual_end': actualEnd!.toUtc().toIso8601String(),
        'can_start': canStart,
        'can_end': canEnd,
        'can_request_early_end': canRequestEarlyEnd,
        if (role != null) 'role': role,
        if (notes != null) 'notes': notes,
        if (site != null) 'site': site!.toJson(),
        if (geofence != null) 'geofence': geofence!.toJson(),
        if (durationHours != null) 'duration_hours': durationHours,
        if (endType != null) 'end_type': endType,
        if (earlyEndStatus != null)
          'early_end_request': {
            'status': earlyEndStatus,
            if (earlyEndReason != null) 'reason': earlyEndReason,
            if (earlyEndNote != null) 'note': earlyEndNote,
          },
      };

  factory CurrentShiftModel.fromJson(Map<String, dynamic> json) {
    return CurrentShiftModel(
      id: json['id'] as String,
      reference: json['reference'] as String?,
      status: json['status'] as String,
      // Parse as UTC then localise. `parseServerUtc` handles a zone-less server
      // string (interpreted as UTC) so wall-clock times can't drift by the
      // device offset (M5). A null/unparseable required time is surfaced as a
      // clear FormatException the caller logs — rather than an obscure cast error
      // — and `CurrentShiftNotifier.fetch` swallows it, keeping the cached shift
      // (M4).
      scheduledStart: (parseServerUtc(json['scheduled_start'] as String?) ??
              (throw const FormatException('shift.scheduled_start missing/invalid')))
          .toLocal(),
      scheduledEnd: (parseServerUtc(json['scheduled_end'] as String?) ??
              (throw const FormatException('shift.scheduled_end missing/invalid')))
          .toLocal(),
      actualStart: parseServerUtc(json['actual_start'] as String?)?.toLocal(),
      actualEnd: parseServerUtc(json['actual_end'] as String?)?.toLocal(),
      canStart: json['can_start'] as bool? ?? false,
      canEnd: json['can_end'] as bool? ?? false,
      canRequestEarlyEnd: json['can_request_early_end'] as bool? ?? false,
      role: json['role'] as String?,
      notes: json['notes'] as String?,
      // Parse the nested site/geofence defensively: a malformed sub-object (e.g. a
      // geofence with null/odd `coordinates`) must NOT throw out of the whole
      // shift parse — otherwise one bad field drops the ENTIRE shift on every 20s
      // poll and strands the guard on a disabled START button with no explanation.
      site: _parseNested(json['site'], ShiftSiteModel.fromJson),
      geofence: _parseNested(json['geofence'], ShiftGeofenceModel.fromJson),
      durationHours: (json['duration_hours'] as num?)?.toDouble(),
      endType: json['end_type'] as String?,
      earlyEndStatus: _earlyEnd(json)?['status'] as String?,
      earlyEndReason: _earlyEnd(json)?['reason'] as String?,
      earlyEndNote: _earlyEnd(json)?['note'] as String?,
    );
  }

  /// The server's optional early-end request object. Tolerates absence and any
  /// non-object shape so a missing field never breaks shift parsing.
  static Map<String, dynamic>? _earlyEnd(Map<String, dynamic> json) {
    final raw = json['early_end_request'];
    return raw is Map<String, dynamic> ? raw : null;
  }

  /// Parses an optional nested object with [parse], returning null when it's
  /// absent, the wrong shape, or [parse] itself throws on a malformed sub-field —
  /// so a bad site/geofence degrades to null instead of dropping the whole shift.
  static T? _parseNested<T>(
    dynamic raw,
    T Function(Map<String, dynamic>) parse,
  ) {
    if (raw is! Map<String, dynamic>) return null;
    try {
      return parse(raw);
    } catch (_) {
      return null;
    }
  }

  CurrentShiftModel copyWith({
    String? status,
    bool? canStart,
    bool? canEnd,
    bool? canRequestEarlyEnd,
    DateTime? actualStart,
    DateTime? actualEnd,
    double? durationHours,
    String? endType,
    String? earlyEndStatus,
    String? earlyEndReason,
    String? earlyEndNote,
  }) {
    return CurrentShiftModel(
      id: id,
      reference: reference,
      status: status ?? this.status,
      scheduledStart: scheduledStart,
      scheduledEnd: scheduledEnd,
      actualStart: actualStart ?? this.actualStart,
      actualEnd: actualEnd ?? this.actualEnd,
      canStart: canStart ?? this.canStart,
      canEnd: canEnd ?? this.canEnd,
      canRequestEarlyEnd: canRequestEarlyEnd ?? this.canRequestEarlyEnd,
      role: role,
      notes: notes,
      site: site,
      geofence: geofence,
      durationHours: durationHours ?? this.durationHours,
      endType: endType ?? this.endType,
      earlyEndStatus: earlyEndStatus ?? this.earlyEndStatus,
      earlyEndReason: earlyEndReason ?? this.earlyEndReason,
      earlyEndNote: earlyEndNote ?? this.earlyEndNote,
    );
  }
}
