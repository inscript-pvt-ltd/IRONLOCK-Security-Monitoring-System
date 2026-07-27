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

  // Id ranges for the per-shift scheduled check reminders. Each schedule mark
  // gets `base + index`; cancel walks the whole range. Kept clear of _endShiftId
  // (1001) and the photo-review range (2000+).
  static const int _wakeCheckBase = 3000;
  static const int _photoCheckBase = 4000;
  // iOS hard-caps PENDING local notifications at 64 TOTAL across the app, and
  // silently drops anything past that. Offline local reminders are the ONLY
  // prompt an offline guard gets, so we must stay under the cap even on a long
  // shift. The two check types are scheduled independently and can't see each
  // other's count, so each is capped so their sum plus the shift-end reminder
  // can't exceed 64: [_maxScheduledPerType]·2 + 1 ≤ 64 (L5).
  static const int _maxScheduledPerType = 31;
  // Width of the id band cancel walks when clearing a type's reminders. Kept
  // wider than the schedule cap so cancel still clears a set left by an older
  // build that scheduled more; walking spare ids is a harmless no-op.
  static const int _maxScheduledChecks = 64;

  // iOS presentation flags. Without these a scheduled/shown notification does NOT
  // surface a banner while the app is in the FOREGROUND on iOS (the OS suppresses
  // it unless the delegate opts in) — so a guard actively using the app would get
  // no visible welfare/photo prompt. On Android these flags are ignored. Applied
  // to every notification below.
  static const DarwinNotificationDetails _darwin = DarwinNotificationDetails(
    presentAlert: true,
    presentBadge: true,
    presentSound: true,
    presentBanner: true,
  );

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
  ///
  /// Also requests **exact-alarm** permission on Android. Without it,
  /// `exactAllowWhileIdle` throws and scheduling falls back to an inexact alarm,
  /// which Doze batches until the device next wakes — so an OFFLINE welfare/photo
  /// reminder on a locked phone fires late (or only on unlock/reconnect). Exact
  /// alarms are core to a lone-worker welfare check firing on time. Best-effort:
  /// if the user withholds it, we still fall back to inexact rather than fail.
  static Future<void> requestPermission() async {
    await init();
    await _plugin
        .resolvePlatformSpecificImplementation<
            IOSFlutterLocalNotificationsPlugin>()
        ?.requestPermissions(alert: true, badge: true, sound: true);
    final android = _plugin.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin>();
    await android?.requestNotificationsPermission();
    // Android 12 (API 31–32): SCHEDULE_EXACT_ALARM is runtime-granted. 13+ uses
    // USE_EXACT_ALARM (install-granted) so this is a no-op there. Wrapped because
    // it opens a system settings screen / can throw on OEM ROMs.
    try {
      await android?.requestExactAlarmsPermission();
    } catch (e) {
      if (kDebugMode) debugPrint('[notif] requestExactAlarms failed: $e');
    }
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
      iOS: _darwin,
    );

    final body = shiftRef != null
        ? 'Your shift $shiftRef has ended — open IronLock and tap END to close it.'
        : 'Your shift has ended — open IronLock and tap END to close it.';
    try {
      // Try an exact alarm first so the reminder fires on time even under Doze
      // (consistent with the welfare/photo check reminders), falling back to an
      // inexact one if exact-alarm permission is withheld — better slightly late
      // than not at all (L6).
      try {
        await _plugin.zonedSchedule(
          id: _endShiftId,
          title: 'Shift ended',
          body: body,
          scheduledDate: when,
          notificationDetails: details,
          androidScheduleMode: AndroidScheduleMode.exactAllowWhileIdle,
        );
      } catch (_) {
        await _plugin.zonedSchedule(
          id: _endShiftId,
          title: 'Shift ended',
          body: body,
          scheduledDate: when,
          notificationDetails: details,
          androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
        );
      }
    } catch (e) {
      // Never let a reminder-scheduling failure break shift start.
      if (kDebugMode) debugPrint('[notif] scheduleShiftEnd failed: $e');
    }
  }

  static Future<void> cancelShiftEnd() async {
    await init();
    await _plugin.cancel(id: _endShiftId);
  }

  /// Schedule an OFFLINE welfare-check reminder at each future schedule [marks]
  /// time. A push can't reach an offline device, so these local notifications are
  /// the *only* prompt when the guard is offline/backgrounded — the OS fires them
  /// even if the app is killed. Scheduling replaces any previously-scheduled set.
  static Future<void> scheduleWakefulnessChecks(List<DateTime> marks) =>
      _scheduleChecks(
        marks,
        base: _wakeCheckBase,
        channelId: 'wakefulness_checks',
        channelName: 'Welfare checks',
        channelDesc: 'Reminds you to complete a welfare check-in',
        title: 'Welfare check due',
        body: 'Open IronLock now and enter your check-in code.',
      );

  /// Schedule an OFFLINE photo-verification reminder at each future [marks] time.
  /// Same rationale as [scheduleWakefulnessChecks].
  static Future<void> schedulePhotoChecks(List<DateTime> marks) =>
      _scheduleChecks(
        marks,
        base: _photoCheckBase,
        channelId: 'photo_checks',
        channelName: 'Photo checks',
        channelDesc: 'Reminds you to take a verification photo',
        title: 'Photo check due',
        body: 'Open IronLock now to take your verification photo.',
      );

  static Future<void> cancelWakefulnessChecks() => _cancelRange(_wakeCheckBase);
  static Future<void> cancelPhotoChecks() => _cancelRange(_photoCheckBase);

  /// Schedules one local notification per future mark. Past marks are skipped;
  /// the set is capped at [_maxScheduledChecks]. Tries an **exact** alarm first
  /// (a welfare window is short, so timing matters) and falls back to an inexact
  /// one if the OS withholds exact-alarm permission — better a slightly-late
  /// prompt than none. Best-effort throughout: a scheduling failure is swallowed.
  static Future<void> _scheduleChecks(
    List<DateTime> marks, {
    required int base,
    required String channelId,
    required String channelName,
    required String channelDesc,
    required String title,
    required String body,
  }) async {
    // Whole method is best-effort: a notification failure (no platform in tests,
    // permission denied, exact-alarm withheld) must NEVER break shift start.
    try {
      await init();
      // Replace any set left from a previous provisioning/relaunch.
      await _cancelRange(base);
      if (marks.isEmpty) return;
      await requestPermission();

      final now = DateTime.now();
      final details = NotificationDetails(
        android: AndroidNotificationDetails(
          channelId,
          channelName,
          channelDescription: channelDesc,
          importance: Importance.max,
          priority: Priority.high,
        ),
        iOS: _darwin,
      );

      var i = 0;
      for (final mark in marks) {
        if (i >= _maxScheduledPerType) break; // stay within the iOS 64 budget (L5)
        if (!now.isBefore(mark)) continue; // already past — nothing to remind
        final when = tz.TZDateTime.from(mark.toUtc(), tz.UTC);
        final id = base + i;
        i++;
        try {
          await _plugin.zonedSchedule(
            id: id,
            title: title,
            body: body,
            scheduledDate: when,
            notificationDetails: details,
            androidScheduleMode: AndroidScheduleMode.exactAllowWhileIdle,
          );
        } catch (_) {
          // Exact alarms not permitted (Android 12+ without the permission) —
          // fall back to an inexact alarm so the reminder still fires.
          await _plugin.zonedSchedule(
            id: id,
            title: title,
            body: body,
            scheduledDate: when,
            notificationDetails: details,
            androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
          );
        }
      }
    } catch (e) {
      if (kDebugMode) debugPrint('[notif] scheduleChecks failed: $e');
    }
  }

  /// Deterministic 31-bit hash of [s] (FNV-1a). Unlike `String.hashCode` — whose
  /// seed can differ between the FCM **background** isolate (where a photo-request
  /// notification is raised at arrival) and the **foreground** (where the poll
  /// raises it) — this is pure arithmetic, so the same request id yields the same
  /// id in both, making the "replace, don't stack" intent reliable. A 16-bit band
  /// (65 536 slots) keeps within-type collisions rare (L4).
  static int _stableHash(String s) {
    var h = 0x811c9dc5;
    for (final c in s.codeUnits) {
      h ^= c;
      h = (h * 0x01000193) & 0x7fffffff;
    }
    return h;
  }

  static Future<void> _cancelRange(int base) async {
    try {
      await init();
      for (var i = 0; i < _maxScheduledChecks; i++) {
        await _plugin.cancel(id: base + i);
      }
    } catch (e) {
      if (kDebugMode) debugPrint('[notif] cancelRange failed: $e');
    }
  }

  /// Fire a one-off tray notification that an online/manual photo request is
  /// waiting *now*. Mirrors the wakefulness prompt so the guard is alerted even
  /// when a data-only push can't draw its own banner (notably iOS) — the window
  /// otherwise only opens silently via the foreground `photos/pending` poll.
  /// [requestId] derives a stable id so a re-delivered push / re-poll replaces
  /// rather than stacks. Best-effort — never throws.
  static Future<void> showPhotoRequest({required String requestId}) async {
    await init();
    await requestPermission();

    const details = NotificationDetails(
      android: AndroidNotificationDetails(
        'photo_requests',
        'Photo requests',
        channelDescription: 'Tells you a verification photo is required now',
        importance: Importance.max,
        priority: Priority.high,
      ),
      iOS: _darwin,
    );

    try {
      await _plugin.show(
        // Stable per-request id in a DISTINCT high range [200000, 265535] that
        // can't overlap the shift-end (1001), wakefulness (3000-3063), photo
        // (4000-4063), or review (100000-165535) ids — otherwise a request
        // notification could cancel/replace one of those on the OS. Uses the
        // isolate-stable hash so the background-arrival and foreground-poll paths
        // compute the SAME id for one request (L4).
        id: 200000 + (_stableHash(requestId) & 0xFFFF),
        title: 'Photo required',
        body: 'Open IronLock now to take your verification photo.',
        notificationDetails: details,
      );
    } catch (e) {
      if (kDebugMode) debugPrint('[notif] showPhotoRequest failed: $e');
    }
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
      iOS: _darwin,
    );

    try {
      await _plugin.show(
        // Stable per-review id in a DISTINCT high range [100000, 165535] so a
        // review can't stack AND can't collide with the scheduled reminder ids
        // (3000-3063 / 4000-4063) or the request ids (200000-265535). Uses the
        // isolate-stable hash for consistency with the request path (L4).
        id: 100000 + (_stableHash(requestId) & 0xFFFF),
        title: title,
        body: body,
        notificationDetails: details,
      );
    } catch (e) {
      if (kDebugMode) debugPrint('[notif] showPhotoReview failed: $e');
    }
  }
}
