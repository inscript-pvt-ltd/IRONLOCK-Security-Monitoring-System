import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/providers/wakefulness_provider.dart';

/// Backend per-site settings (2026-07-26) mean `wakefulness.schedule` can now
/// come back `[]` when an admin turns wakefulness OFF for a site — while the
/// `totp_seed` is STILL sent (it's needed for a manual/offline check). So the
/// on/off signal is the SCHEDULE, never the seed. These lock that in.
void main() {
  group('WakefulnessProvisioning.fromJson — per-site on/off', () {
    test('OFF: seed present + empty schedule → parsed, but ZERO marks (nothing fires)', () {
      final p = WakefulnessProvisioning.fromJson({
        'totp_seed': 'JBSWY3DPEHPK3PXP',
        'totp_period_seconds': 30,
        'totp_digits': 4,
        'response_seconds': 60,
        'schedule': <dynamic>[], // wakefulness switched off for this shift
      });
      expect(p, isNotNull, reason: 'seed is still returned when off');
      expect(p!.seed, 'JBSWY3DPEHPK3PXP');
      expect(p.marks, isEmpty,
          reason: 'empty schedule ⇒ no marks ⇒ checkSchedule fires nothing');
    });

    test('ON: seed + marks → parsed with marks', () {
      final p = WakefulnessProvisioning.fromJson({
        'totp_seed': 'JBSWY3DPEHPK3PXP',
        'schedule': ['2026-07-27T19:00:00Z', '2026-07-27T19:35:00Z'],
      });
      expect(p, isNotNull);
      expect(p!.marks, hasLength(2));
    });

    test('no seed at all → null (the local-mock case, unchanged)', () {
      expect(WakefulnessProvisioning.fromJson({'schedule': <dynamic>[]}), isNull);
    });
  });
}
