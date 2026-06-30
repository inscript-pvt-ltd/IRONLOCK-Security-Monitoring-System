class ShiftSiteModel {
  const ShiftSiteModel({
    required this.id,
    required this.name,
    this.gracePeriodMinutes,
  });

  final String id;
  final String name;
  final int? gracePeriodMinutes;

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

  factory CurrentShiftModel.fromJson(Map<String, dynamic> json) {
    return CurrentShiftModel(
      id: json['id'] as String,
      reference: json['reference'] as String?,
      status: json['status'] as String,
      scheduledStart: DateTime.parse(json['scheduled_start'] as String).toLocal(),
      scheduledEnd: DateTime.parse(json['scheduled_end'] as String).toLocal(),
      actualStart: json['actual_start'] != null
          ? DateTime.parse(json['actual_start'] as String).toLocal()
          : null,
      actualEnd: json['actual_end'] != null
          ? DateTime.parse(json['actual_end'] as String).toLocal()
          : null,
      canStart: json['can_start'] as bool? ?? false,
      canEnd: json['can_end'] as bool? ?? false,
      canRequestEarlyEnd: json['can_request_early_end'] as bool? ?? false,
      role: json['role'] as String?,
      notes: json['notes'] as String?,
      site: json['site'] != null
          ? ShiftSiteModel.fromJson(json['site'] as Map<String, dynamic>)
          : null,
      geofence: json['geofence'] != null
          ? ShiftGeofenceModel.fromJson(json['geofence'] as Map<String, dynamic>)
          : null,
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
