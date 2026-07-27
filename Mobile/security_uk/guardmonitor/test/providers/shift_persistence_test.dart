import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/data/offline_queue_db.dart';
import 'package:guardmonitor/providers/shift_provider.dart';

void main() {
  group('ShiftState persistence round-trip', () {
    test('an active shift survives toJson → fromJson', () {
      final s = ShiftState(
        active: true,
        id: 'shift-1',
        shiftRef: '#SH-2847',
        startTime: DateTime.utc(2026, 7, 24, 9, 0),
        welfareChecksTotal: 3,
        welfareChecksPassed: 2,
        photosTotal: 1,
        photosPassed: 1,
      );
      final back = ShiftState.fromJson(s.toJson());
      expect(back, isNotNull);
      expect(back!.active, isTrue);
      expect(back.id, 'shift-1');
      expect(back.shiftRef, '#SH-2847');
      expect(back.welfareChecksTotal, 3);
      expect(back.welfareChecksPassed, 2);
      expect(back.photosTotal, 1);
      expect(back.photosPassed, 1);
      expect(back.startTime!.toUtc(), DateTime.utc(2026, 7, 24, 9, 0));
    });

    test('an inactive / blank shift restores as null (nothing to resume)', () {
      expect(ShiftState.fromJson(const ShiftState().toJson()), isNull);
      expect(ShiftState.fromJson({'active': true}), isNull,
          reason: 'active but no id → not restorable');
    });
  });

  group('SyncProgress', () {
    test('fraction fills as items drain; completed reads 100%', () {
      expect(const SyncProgress(pending: 5, total: 5).fraction, 0.0);
      expect(const SyncProgress(pending: 2, total: 5).fraction, closeTo(0.6, 1e-9));
      expect(const SyncProgress(pending: 0, total: 5, completed: true).fraction, 1.0);
    });

    test('visibility: shown while active or briefly when completed', () {
      expect(const SyncProgress().visible, isFalse);
      expect(const SyncProgress(pending: 3, total: 3).visible, isTrue);
      expect(const SyncProgress(total: 3, completed: true).visible, isTrue);
    });
  });
}
