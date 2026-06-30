import 'package:flutter/foundation.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:timezone/data/latest_all.dart' as tz;
import 'package:timezone/timezone.dart' as tz;

/// Local (on-device) notifications. Currently used for the single "your shift
/// has ended, go and end it" reminder scheduled at the shift's `scheduled_end`.
///
/// Deliberately **local**, not push: the OS holds the scheduled notification
/// and fires it even if the app is backgrounded or terminated, with no backend
/// infrastructure. It's a *reminder*, not a guarantee — the backend auto-close
/// is still the real safety net if the guard never acts.
class NotificationService {
  NotificationService._();

  static final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();
  static bool _inited = false;

  // Fixed id so scheduling again replaces the previous reminder, and cancel
  // can target it precisely.
  static const int _endShiftId = 1001;

  static Future<void> init() async {
    if (_inited) return;
    tz.initializeTimeZones();

    const android = AndroidInitializationSettings('@mipmap/ic_launcher');
    // Don't prompt at init — we ask contextually when a shift starts.
    const darwin = DarwinInitializationSettings(
      requestAlertPermission: false,
      requestBadgePermission: false,
      requestSoundPermission: false,
    );
    await _plugin.initialize(
      settings: const InitializationSettings(android: android, iOS: darwin),
    );
    _inited = true;
  }

  /// Ask for notification permission (iOS always; Android 13+). Safe to call
  /// repeatedly — the OS only shows the prompt once.
  static Future<void> requestPermission() async {
    await init();
    await _plugin
        .resolvePlatformSpecificImplementation<
            IOSFlutterLocalNotificationsPlugin>()
        ?.requestPermissions(alert: true, badge: true, sound: true);
    await _plugin
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.requestNotificationsPermission();
  }

  /// Schedule the "shift ended" reminder for [scheduledEnd]. No-op if that
  /// time is already in the past. Scheduling again replaces any existing one.
  static Future<void> scheduleShiftEnd(
    DateTime scheduledEnd, {
    String? shiftRef,
  }) async {
    await init();
    if (!DateTime.now().isBefore(scheduledEnd)) return; // already past
    await requestPermission();

    // Schedule at the absolute instant. We build the TZDateTime in UTC and tell
    // iOS to interpret it as an absolute time, so we don't need the device's
    // IANA zone name — it fires at the right wall-clock moment regardless.
    final when = tz.TZDateTime.from(scheduledEnd.toUtc(), tz.UTC);

    const details = NotificationDetails(
      android: AndroidNotificationDetails(
        'shift_end',
        'Shift reminders',
        channelDescription: 'Reminds you to end your shift when it finishes',
        importance: Importance.high,
        priority: Priority.high,
      ),
      iOS: DarwinNotificationDetails(),
    );

    try {
      await _plugin.zonedSchedule(
        id: _endShiftId,
        title: 'Shift ended',
        body: shiftRef != null
            ? 'Your shift $shiftRef has ended — open IronLock and tap END to close it.'
            : 'Your shift has ended — open IronLock and tap END to close it.',
        scheduledDate: when,
        notificationDetails: details,
        androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
      );
    } catch (e) {
      // Never let a reminder-scheduling failure break shift start.
      if (kDebugMode) debugPrint('[notif] scheduleShiftEnd failed: $e');
    }
  }

  static Future<void> cancelShiftEnd() async {
    await init();
    await _plugin.cancel(id: _endShiftId);
  }

  /// Fire a one-off tray notification that a submitted photo was reviewed. Used
  /// by the poll path so the guard is notified even when push can't deliver
  /// (e.g. iOS without APNs); the push path relies on the OS drawing the
  /// notification block itself. [requestId] derives a stable id so re-firing the
  /// same review replaces rather than stacks.
  static Future<void> showPhotoReview({
    required String decision,
    String? note,
    required String requestId,
  }) async {
    await init();
    await requestPermission();

    final approved = decision.toUpperCase() == 'APPROVED';
    final title = approved ? 'Photo approved' : 'Photo rejected';
    final body = approved
        ? 'Your verification photo was approved.'
        : (note != null && note.isNotEmpty)
            ? 'Rejected: $note'
            : 'Your verification photo was rejected.';

    const details = NotificationDetails(
      android: AndroidNotificationDetails(
        'photo_reviews',
        'Photo reviews',
        channelDescription: 'Tells you when a supervisor reviews your photo',
        importance: Importance.high,
        priority: Priority.high,
      ),
      iOS: DarwinNotificationDetails(),
    );

    try {
      await _plugin.show(
        // Stable per-review id (positive) so the same review can't stack.
        id: 2000 + (requestId.hashCode & 0xFFF),
        title: title,
        body: body,
        notificationDetails: details,
      );
    } catch (e) {
      if (kDebugMode) debugPrint('[notif] showPhotoReview failed: $e');
    }
  }
}
