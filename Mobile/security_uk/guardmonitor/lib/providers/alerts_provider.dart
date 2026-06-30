import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../services/alerts_service.dart';

enum AlertSeverity { urgent, notice, reminder }

class AppAlert {
  AppAlert({
    required this.id,
    required this.severity,
    required this.title,
    required this.description,
    required this.time,
    this.dismissed = false,
  });

  final String id;
  final AlertSeverity severity;
  final String title;
  final String description;
  final String time;
  bool dismissed;

  AppAlert copyWith({bool? dismissed}) => AppAlert(
        id: id,
        severity: severity,
        title: title,
        description: description,
        time: time,
        dismissed: dismissed ?? this.dismissed,
      );

  factory AppAlert.fromJson(Map<String, dynamic> json) {
    AlertSeverity parseSeverity(String s) => switch (s) {
          'urgent' => AlertSeverity.urgent,
          'notice' => AlertSeverity.notice,
          _ => AlertSeverity.reminder,
        };

    return AppAlert(
      id: json['id'].toString(),
      severity: parseSeverity(json['severity'] as String? ?? 'reminder'),
      title: json['title'] as String,
      description: json['description'] as String,
      time: json['time'] as String? ?? 'just now',
      dismissed: json['dismissed'] as bool? ?? false,
    );
  }
}

class AlertsNotifier extends Notifier<List<AppAlert>> {
  @override
  List<AppAlert> build() {
    _fetchFromApi();
    // Start empty — never seed fabricated alerts. Real alerts only ever come
    // from the server (_fetchFromApi) or genuine in-app events (prepend, e.g. a
    // failed welfare check). Seeding fakes risked showing a guard a bogus
    // "supervisor has been notified" / "outside patrol zone" notice as real.
    return const [];
  }

  void _fetchFromApi() async {
    try {
      final raw = await ref.read(alertsServiceProvider).fetchAlerts();
      state = raw.map(AppAlert.fromJson).toList();
    } catch (_) {
      // API unreachable — leave whatever we have (empty, or real in-app events).
    }
  }

  void dismiss(String id) {
    state = [
      for (final a in state)
        if (a.id == id) a.copyWith(dismissed: true) else a,
    ];
    // Best-effort API dismiss — fire and forget
    ref.read(alertsServiceProvider).dismissAlert(id).ignore();
  }

  void prepend(AppAlert alert) => state = [alert, ...state];

  Future<void> refresh() async {
    final raw = await ref.read(alertsServiceProvider).fetchAlerts();
    state = raw.map(AppAlert.fromJson).toList();
  }

  int get unreadCount => state.where((a) => !a.dismissed).length;
}

final alertsProvider =
    NotifierProvider<AlertsNotifier, List<AppAlert>>(AlertsNotifier.new);
