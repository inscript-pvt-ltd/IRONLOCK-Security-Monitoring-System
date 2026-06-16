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
    return _defaultAlerts();
  }

  void _fetchFromApi() async {
    try {
      final raw = await ref.read(alertsServiceProvider).fetchAlerts();
      state = raw.map(AppAlert.fromJson).toList();
    } catch (_) {
      // Keep default alerts if API unreachable
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

  static List<AppAlert> _defaultAlerts() => [
        AppAlert(
          id: 'a1',
          severity: AlertSeverity.urgent,
          title: 'Welfare check not completed',
          description:
              'A check-in code was not entered in time — your supervisor has been notified',
          time: '4m ago',
        ),
        AppAlert(
          id: 'a2',
          severity: AlertSeverity.urgent,
          title: 'Outside patrol zone',
          description:
              'You were outside your assigned area for more than 5 minutes',
          time: '12m ago',
        ),
        AppAlert(
          id: 'a3',
          severity: AlertSeverity.notice,
          title: 'Photo request not completed',
          description:
              'The photo window expired — you will receive a new request shortly',
          time: '28m ago',
        ),
        AppAlert(
          id: 'a4',
          severity: AlertSeverity.notice,
          title: 'Photo flagged for review',
          description:
              'Your last photo has been sent for supervisor review — no action needed',
          time: '1h ago',
        ),
        AppAlert(
          id: 'a5',
          severity: AlertSeverity.reminder,
          title: 'SIA licence renewal reminder',
          description:
              'Your licence expires in December 2026 — please renew with your supervisor',
          time: '2d ago',
          dismissed: true,
        ),
      ];
}

final alertsProvider =
    NotifierProvider<AlertsNotifier, List<AppAlert>>(AlertsNotifier.new);
