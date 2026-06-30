import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/models/current_shift_model.dart';
import 'package:guardmonitor/providers/app_providers.dart';
import 'package:guardmonitor/services/shift_service.dart';

/// The live backend's `POST /shifts/{id}/start` and `/end` return only a
/// *partial* payload (id, status, actual_start, …) — not a whole shift. An
/// earlier bug fed that partial body straight into CurrentShiftModel.fromJson,
/// which threw on the missing scheduled_start/end and left the START button
/// stuck. The fix is the "merge changed fields into the existing full shift"
/// logic in CurrentShiftNotifier. These tests pin that contract down.
class _FakeShiftService extends ShiftService {
  _FakeShiftService({this.current, this.startAt, this.endResult}) : super(Dio());

  final CurrentShiftModel? current;
  final DateTime? startAt;
  final ({DateTime? actualStart, DateTime? actualEnd, double? durationHours, String? endType})? endResult;

  @override
  Future<CurrentShiftModel?> fetchCurrent() async => current;

  @override
  Future<
      ({
        DateTime? actualStart,
        Map<String, dynamic>? wakefulness,
        Map<String, dynamic>? photos,
      })> startShift(String shiftId) async =>
      (actualStart: startAt, wakefulness: null, photos: null);

  @override
  Future<({DateTime? actualStart, DateTime? actualEnd, double? durationHours, String? endType})>
      endShift(
    String shiftId, {
    bool endedEarly = false,
    String? reason,
    String? note,
  }) async =>
          endResult ??
          (actualStart: null, actualEnd: null, durationHours: null, endType: null);
}

CurrentShiftModel _seed() => CurrentShiftModel(
      id: 'shift-1',
      reference: 'SH-2847',
      status: 'checked_in',
      scheduledStart: DateTime.utc(2026, 6, 18, 9),
      scheduledEnd: DateTime.utc(2026, 6, 18, 17),
      canStart: true,
      canEnd: false,
      site: const ShiftSiteModel(id: 'site-1', name: 'Canary Wharf Tower'),
      role: 'Static guard',
    );

void main() {
  ProviderContainer makeContainer(_FakeShiftService fake) {
    final container = ProviderContainer(
      overrides: [shiftServiceProvider.overrideWithValue(fake)],
    );
    addTearDown(container.dispose);
    return container;
  }

  group('CurrentShiftNotifier.start()', () {
    test('merges the server actual_start into the existing shift and flips to active', () async {
      final startedAt = DateTime.utc(2026, 6, 18, 9, 3);
      final container = makeContainer(
        _FakeShiftService(current: _seed(), startAt: startedAt),
      );

      // Realistic flow: GET /shifts/current first, then start.
      await container.read(currentShiftProvider.notifier).fetch();
      final updated = await container.read(currentShiftProvider.notifier).start();

      expect(updated.status, 'active');
      expect(updated.canStart, isFalse);
      expect(updated.canEnd, isTrue);
      expect(updated.actualStart, startedAt);
      // Fields not present in the partial response must be preserved.
      expect(updated.reference, 'SH-2847');
      expect(updated.scheduledStart, DateTime.utc(2026, 6, 18, 9));
      expect(updated.site?.name, 'Canary Wharf Tower');
      expect(updated.role, 'Static guard');
      // displayRef still resolves from the preserved reference.
      expect(updated.displayRef, '#SH-2847');
    });

    test('throws when there is no current shift to start', () async {
      final container = makeContainer(_FakeShiftService(current: null));
      await container.read(currentShiftProvider.notifier).fetch();

      expect(
        () => container.read(currentShiftProvider.notifier).start(),
        throwsStateError,
      );
    });
  });

  group('CurrentShiftNotifier.end()', () {
    test('merges actual_end + duration and flips to completed', () async {
      final endedAt = DateTime.utc(2026, 6, 18, 17, 1);
      final container = makeContainer(
        _FakeShiftService(
          current: _seed(),
          endResult: (actualStart: null, actualEnd: endedAt, durationHours: 8.0, endType: 'guard'),
        ),
      );

      await container.read(currentShiftProvider.notifier).fetch();
      final updated = await container.read(currentShiftProvider.notifier).end();

      expect(updated.status, 'completed');
      expect(updated.canStart, isFalse);
      expect(updated.canEnd, isFalse);
      expect(updated.actualEnd, endedAt);
      expect(updated.durationHours, 8.0);
      expect(updated.reference, 'SH-2847');
    });
  });

  group('ShiftNotifier.reconcileServerClosed()', () {
    test('is a no-op when inactive — the guard that stops it clashing with a '
        'guard-initiated end', () {
      final container = makeContainer(_FakeShiftService(current: null));
      expect(container.read(shiftProvider).active, isFalse);

      // Must not throw and must stay inactive. The early `if (!active) return`
      // is what prevents a normal END (whose completed shift arrives while the
      // home listener still sees active mid-teardown) from double-firing — and
      // means it only reads GPS/notification providers when there's genuinely an
      // active shift to tear down.
      container.read(shiftProvider.notifier).reconcileServerClosed();

      expect(container.read(shiftProvider).active, isFalse);
    });
  });
}
