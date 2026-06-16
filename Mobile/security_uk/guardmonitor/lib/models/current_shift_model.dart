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
    this.actualStart,
    this.actualEnd,
    this.role,
    this.notes,
    this.site,
    this.geofence,
    this.durationHours,
  });

  final String id;
  final String status; // scheduled | active | completed | cancelled
  final DateTime scheduledStart;
  final DateTime scheduledEnd;
  final DateTime? actualStart;
  final DateTime? actualEnd;
  final bool canStart;
  final bool canEnd;
  final String? role;
  final String? notes;
  final ShiftSiteModel? site;
  final ShiftGeofenceModel? geofence;
  final double? durationHours;

  /// The spec has no human-readable shift reference (only a UUID `id`), so
  /// derive a short display label until the backend adds a real field.
  String get displayRef => '#SH-${id.replaceAll('-', '').substring(0, 6).toUpperCase()}';

  factory CurrentShiftModel.fromJson(Map<String, dynamic> json) {
    return CurrentShiftModel(
      id: json['id'] as String,
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
      role: json['role'] as String?,
      notes: json['notes'] as String?,
      site: json['site'] != null
          ? ShiftSiteModel.fromJson(json['site'] as Map<String, dynamic>)
          : null,
      geofence: json['geofence'] != null
          ? ShiftGeofenceModel.fromJson(json['geofence'] as Map<String, dynamic>)
          : null,
      durationHours: (json['duration_hours'] as num?)?.toDouble(),
    );
  }
}
