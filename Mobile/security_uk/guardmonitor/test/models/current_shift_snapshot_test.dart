import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/models/current_shift_model.dart';

/// The current-shift snapshot is persisted so a cold start after a swipe-kill
/// can render the full shift card (site name, schedule, overdue banner) offline,
/// instead of showing a blank "—" while `GET /shifts/current` is null-for-active
/// or unreachable. This locks in the `toJson → fromJson` round-trip.
void main() {
  test('an active shift round-trips through toJson/fromJson (site + schedule)', () {
    final original = CurrentShiftModel(
      id: 'shift-1',
      reference: 'SH-2847',
      status: 'active',
      scheduledStart: DateTime.parse('2026-07-24T09:00:00Z').toLocal(),
      scheduledEnd: DateTime.parse('2026-07-24T17:00:00Z').toLocal(),
      actualStart: DateTime.parse('2026-07-24T09:02:00Z').toLocal(),
      canStart: false,
      canEnd: true,
      role: 'Security Officer',
      site: const ShiftSiteModel(
        id: 'site-9',
        name: 'Canary Wharf Tower',
        gracePeriodMinutes: 10,
      ),
      geofence: const ShiftGeofenceModel(
        id: 'gf-1',
        name: 'Perimeter',
        coordinates: [
          [51.5, -0.02],
          [51.51, -0.02],
          [51.51, -0.01],
        ],
      ),
    );

    final back = CurrentShiftModel.fromJson(original.toJson());

    // The details the guard needs offline all survive.
    expect(back.id, 'shift-1');
    expect(back.displayRef, '#SH-2847');
    expect(back.status, 'active');
    expect(back.site?.name, 'Canary Wharf Tower');
    expect(back.site?.gracePeriodMinutes, 10);
    expect(back.canEnd, isTrue);
    expect(back.role, 'Security Officer');
    // Times round-trip to the exact same instant (UTC-emit → parseServerUtc →
    // toLocal), so the schedule + overdue banner render correctly.
    expect(back.scheduledStart.toUtc(), DateTime.parse('2026-07-24T09:00:00Z'));
    expect(back.scheduledEnd.toUtc(), DateTime.parse('2026-07-24T17:00:00Z'));
    expect(back.actualStart?.toUtc(), DateTime.parse('2026-07-24T09:02:00Z'));
    expect(back.geofence?.coordinates.length, 3);
  });

  test('an early-end pending shift round-trips its request state', () {
    final original = CurrentShiftModel(
      id: 'shift-2',
      status: 'active',
      scheduledStart: DateTime.parse('2026-07-24T09:00:00Z').toLocal(),
      scheduledEnd: DateTime.parse('2026-07-24T17:00:00Z').toLocal(),
      canStart: false,
      canEnd: true,
      earlyEndStatus: 'pending',
      earlyEndReason: 'illness',
      earlyEndNote: 'Feeling unwell, need to leave.',
    );

    final back = CurrentShiftModel.fromJson(original.toJson());
    expect(back.earlyEndPending, isTrue);
    expect(back.earlyEndReason, 'illness');
    expect(back.earlyEndNote, 'Feeling unwell, need to leave.');
  });
}
