import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/models/current_shift_model.dart';

/// These guard the shift-card display logic that bit us repeatedly:
/// the server-supplied `reference` and the UUID fallback, plus the model's
/// tolerance of the partial / optional payloads the live backend actually
/// sends (missing site, geofence, actual_start, can_* flags).
void main() {
  group('CurrentShiftModel.fromJson', () {
    final fullJson = <String, dynamic>{
      'id': '019ed9a0-b6fd-72c8-bf4d-410aa4f498e4',
      'reference': 'SH-2847',
      'status': 'scheduled',
      'scheduled_start': '2026-06-18T09:00:00Z',
      'scheduled_end': '2026-06-18T17:00:00Z',
      'can_start': true,
      'can_end': false,
      'role': 'Static guard',
      'notes': 'Report to north gate',
      'site': {'id': 'site-1', 'name': 'Canary Wharf Tower', 'grace_period_minutes': 10},
      'geofence': {
        'id': 'gf-1',
        'name': 'Perimeter',
        'coordinates': [
          [51.5, -0.02],
          [51.6, -0.03],
        ],
      },
    };

    test('parses a full payload including reference and nested site/geofence', () {
      final shift = CurrentShiftModel.fromJson(fullJson);

      expect(shift.id, '019ed9a0-b6fd-72c8-bf4d-410aa4f498e4');
      expect(shift.reference, 'SH-2847');
      expect(shift.status, 'scheduled');
      expect(shift.canStart, isTrue);
      expect(shift.canEnd, isFalse);
      expect(shift.role, 'Static guard');
      expect(shift.site?.name, 'Canary Wharf Tower');
      expect(shift.site?.gracePeriodMinutes, 10);
      expect(shift.geofence?.coordinates.first, [51.5, -0.02]);
      // Stored as local time, so just assert the instant is right.
      expect(shift.scheduledStart.toUtc(), DateTime.utc(2026, 6, 18, 9));
    });

    test('displayRef prefers the server reference, prefixed with #', () {
      final shift = CurrentShiftModel.fromJson(fullJson);
      expect(shift.displayRef, '#SH-2847');
    });

    test('displayRef falls back to a short UUID-derived code when reference is absent', () {
      final json = Map<String, dynamic>.from(fullJson)..remove('reference');
      final shift = CurrentShiftModel.fromJson(json);

      expect(shift.reference, isNull);
      // First 6 hex chars of the dash-stripped UUID, upper-cased.
      expect(shift.displayRef, '#SH-019ED9');
    });

    test('parses a pending early_end_request and exposes status helpers', () {
      final json = Map<String, dynamic>.from(fullJson)
        ..['status'] = 'active'
        ..['early_end_request'] = {
          'status': 'pending',
          'reason': 'Illness',
          'note': 'Feeling unwell',
        };

      final shift = CurrentShiftModel.fromJson(json);

      expect(shift.earlyEndStatus, 'pending');
      expect(shift.earlyEndReason, 'Illness');
      expect(shift.earlyEndNote, 'Feeling unwell');
      expect(shift.earlyEndPending, isTrue);
      expect(shift.earlyEndApproved, isFalse);
      expect(shift.earlyEndRejected, isFalse);
    });

    test('treats an absent early_end_request as no outstanding request', () {
      final shift = CurrentShiftModel.fromJson(fullJson);

      expect(shift.earlyEndStatus, isNull);
      expect(shift.earlyEndPending, isFalse);
      expect(shift.earlyEndApproved, isFalse);
      expect(shift.earlyEndRejected, isFalse);
    });

    test('tolerates the lean live-backend payload (no site/geofence/flags)', () {
      final lean = <String, dynamic>{
        'id': '019ed9a0-b6fd-72c8-bf4d-410aa4f498e4',
        'status': 'checked_in',
        'scheduled_start': '2026-06-18T09:00:00Z',
        'scheduled_end': '2026-06-18T17:00:00Z',
      };

      final shift = CurrentShiftModel.fromJson(lean);

      expect(shift.status, 'checked_in');
      expect(shift.site, isNull);
      expect(shift.geofence, isNull);
      expect(shift.actualStart, isNull);
      // Absent flags must default to false, never throw.
      expect(shift.canStart, isFalse);
      expect(shift.canEnd, isFalse);
    });
  });
}
