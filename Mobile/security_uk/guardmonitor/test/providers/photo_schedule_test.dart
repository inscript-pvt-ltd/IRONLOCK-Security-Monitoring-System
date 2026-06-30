import 'package:flutter/services.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/providers/photo_provider.dart';
import 'package:guardmonitor/providers/photo_schedule_provider.dart';
import 'package:guardmonitor/services/time_anchor_service.dart';

DateTime _utc(String s) => DateTime.parse(s).toUtc();

/// A TimeAnchorService pinned to a fixed trusted "now" (stands in for the
/// NTP-anchored clock the scheduler must use).
class _FixedTimeAnchor extends TimeAnchorService {
  _FixedTimeAnchor(this._now);
  final DateTime _now;
  @override
  DateTime trustedNow() => _now;
}

void main() {
  group('PhotoProvisioning.fromJson', () {
    test('parses the photos block', () {
      final p = PhotoProvisioning.fromJson({
        'schedule': ['2026-06-30T12:28:00Z', '2026-06-30T13:18:00Z'],
        'response_seconds': 90,
        'offline_nonce_ttl_minutes': 15,
        'max_photos_per_capture': 5,
      })!;
      expect(p.schedule, hasLength(2));
      expect(p.offlineNonceTtlMinutes, 15);
      expect(p.maxPhotosPerCapture, 5);
    });

    test('applies defaults for missing window params', () {
      final p = PhotoProvisioning.fromJson({
        'schedule': ['2026-06-30T12:28:00Z'],
      })!;
      expect(p.responseSeconds, 90);
      expect(p.offlineNonceTtlMinutes, 15);
      expect(p.maxPhotosPerCapture, 5);
    });

    test('null / empty / unparseable schedule → null (not provisioned)', () {
      expect(PhotoProvisioning.fromJson(null), isNull);
      expect(PhotoProvisioning.fromJson({'schedule': []}), isNull);
      expect(PhotoProvisioning.fromJson({'schedule': ['nonsense']}), isNull);
      expect(PhotoProvisioning.fromJson({'schedule': 'x'}), isNull);
    });

    test('round-trips through toJson', () {
      final p = PhotoProvisioning.fromJson({
        'schedule': ['2026-06-30T12:28:00Z'],
        'offline_nonce_ttl_minutes': 20,
      })!;
      final back = PhotoProvisioning.fromJson(p.toJson())!;
      expect(back.offlineNonceTtlMinutes, 20);
      expect(back.schedule.single, _utc('2026-06-30T12:28:00Z'));
    });
  });

  group('dueMark — the firing decision (judged against a TRUSTED now)', () {
    final prov = PhotoProvisioning(
      schedule: [_utc('2026-06-30T12:00:00Z'), _utc('2026-06-30T13:00:00Z')],
      offlineNonceTtlMinutes: 15,
    );

    test('not due yet → null', () {
      expect(prov.dueMark(_utc('2026-06-30T11:59:30Z'), {}), isNull);
    });

    test('due and inside the 15-min window → returns the mark', () {
      expect(prov.dueMark(_utc('2026-06-30T12:05:00Z'), {}),
          _utc('2026-06-30T12:00:00Z'));
      // Exactly at the mark counts as due.
      expect(prov.dueMark(_utc('2026-06-30T12:00:00Z'), {}),
          _utc('2026-06-30T12:00:00Z'));
    });

    test('window lapsed (>15 min late) → null (skipped, server records miss)',
        () {
      expect(prov.dueMark(_utc('2026-06-30T12:16:00Z'), {}), isNull);
    });

    test('already-fired marks are skipped → next due one returned', () {
      final fired = {prov.keyFor(_utc('2026-06-30T12:00:00Z'))};
      // At 13:05 the first is long lapsed + fired; the second is now due.
      expect(prov.dueMark(_utc('2026-06-30T13:05:00Z'), fired),
          _utc('2026-06-30T13:00:00Z'));
    });

    test('tamper scenario: a back-dated clock cannot make a mark fire early',
        () {
      // The trusted (NTP-anchored) now is what we pass in. A guard who set the
      // device clock back to 11:00 changes DateTime.now(), but the scheduler
      // uses trustedNow() — so feeding the *trusted* 11:00 still yields null,
      // and feeding the real 12:05 fires regardless of the device clock.
      expect(prov.dueMark(_utc('2026-06-30T11:00:00Z'), {}), isNull);
      expect(prov.dueMark(_utc('2026-06-30T12:05:00Z'), {}), isNotNull);
    });
  });

  group('checkSchedule gating', () {
    setUpAll(() {
      TestWidgetsFlutterBinding.ensureInitialized();
      // flutter_secure_storage uses a method channel; stub it so provisioning
      // (which persists the schedule) works in the test VM.
      TestDefaultBinaryMessengerBinding.instance.defaultBinaryMessenger
          .setMockMethodCallHandler(
        const MethodChannel('plugins.it_nomads.com/flutter_secure_storage'),
        (call) async => null,
      );
    });

    ProviderContainer containerAt(String now) => ProviderContainer(overrides: [
          timeAnchorServiceProvider.overrideWithValue(_FixedTimeAnchor(_utc(now))),
        ]);

    Future<void> provision(ProviderContainer c) =>
        c.read(photoScheduleProvider.notifier).provisionFromJson({
          'schedule': ['2026-06-30T12:00:00Z'],
          'offline_nonce_ttl_minutes': 15,
        });

    test('OFFLINE + a due mark → flags a scheduled capture', () async {
      final c = containerAt('2026-06-30T12:05:00Z');
      addTearDown(c.dispose);
      await provision(c);
      c.read(photoScheduleProvider.notifier).checkSchedule(offline: true);
      final pending = c.read(pendingPhotoProvider);
      expect(pending.pending, isTrue);
      expect(pending.scheduled, isTrue);
    });

    test('ONLINE → does NOT self-fire (server sends a PHOTO_REQUEST instead)',
        () async {
      final c = containerAt('2026-06-30T12:05:00Z');
      addTearDown(c.dispose);
      await provision(c);
      c.read(photoScheduleProvider.notifier).checkSchedule(offline: false);
      expect(c.read(pendingPhotoProvider).pending, isFalse);
    });

    test('does not fire twice for the same mark', () async {
      final c = containerAt('2026-06-30T12:05:00Z');
      addTearDown(c.dispose);
      await provision(c);
      final n = c.read(photoScheduleProvider.notifier);
      n.checkSchedule(offline: true);
      // Simulate the screen having consumed the pending flag.
      c.read(pendingPhotoProvider.notifier).setPending(false);
      n.checkSchedule(offline: true);
      expect(c.read(pendingPhotoProvider).pending, isFalse, reason: 'already fired');
    });
  });
}
