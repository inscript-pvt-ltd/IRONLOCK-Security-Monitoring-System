import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/models/current_shift_model.dart';
import 'package:guardmonitor/providers/app_providers.dart';
import 'package:guardmonitor/services/shift_service.dart';

/// Audit M1: after a guard requests an early end, the optimistic `pending` lock
/// must survive a `GET /shifts/current` poll whose payload doesn't echo the
/// `early_end_request` object — otherwise the END button silently unlocks
/// mid-wait. A *terminal* server signal (approved/rejected, or the shift
/// completing) must still be honoured. These tests pin that down.
class _SequencedShiftService extends ShiftService {
  _SequencedShiftService(this._fetchResults) : super(Dio());

  final List<CurrentShiftModel?> _fetchResults;
  int _i = 0;

  @override
  Future<CurrentShiftModel?> fetchCurrent() async {
    final r = _fetchResults[_i.clamp(0, _fetchResults.length - 1)];
    if (_i < _fetchResults.length - 1) _i++;
    return r;
  }

  @override
  Future<void> requestEarlyEnd(String shiftId,
      {required String reason, required String note}) async {}
}

CurrentShiftModel _shift({String status = 'active', String? earlyEndStatus}) =>
    CurrentShiftModel(
      id: 'shift-1',
      reference: 'SH-2847',
      status: status,
      scheduledStart: DateTime.utc(2026, 6, 18, 9),
      scheduledEnd: DateTime.utc(2026, 6, 18, 17),
      canStart: false,
      canEnd: true,
      earlyEndStatus: earlyEndStatus,
    );

void main() {
  ProviderContainer makeContainer(_SequencedShiftService fake) {
    final container = ProviderContainer(
      overrides: [shiftServiceProvider.overrideWithValue(fake)],
    );
    addTearDown(container.dispose);
    return container;
  }

  test('a poll that omits early_end_request keeps the local pending lock', () async {
    // Poll 1 seeds state; the request makes it pending; poll 2 omits the field.
    final container = makeContainer(
      _SequencedShiftService([_shift(), _shift()]),
    );
    final notifier = container.read(currentShiftProvider.notifier);

    await notifier.fetch();
    await notifier.requestEarlyEnd(reason: 'Illness', note: 'Feeling unwell, going home');
    expect(container.read(currentShiftProvider)!.earlyEndPending, isTrue);

    // Backend doesn't echo early_end_request — lock must survive.
    await notifier.fetch();
    final s = container.read(currentShiftProvider)!;
    expect(s.earlyEndPending, isTrue);
    expect(s.earlyEndReason, 'Illness');
    expect(s.earlyEndNote, 'Feeling unwell, going home');
  });

  test('an approved status from the server replaces the pending lock', () async {
    final container = makeContainer(
      _SequencedShiftService([_shift(), _shift(earlyEndStatus: 'approved')]),
    );
    final notifier = container.read(currentShiftProvider.notifier);

    await notifier.fetch();
    await notifier.requestEarlyEnd(reason: 'Illness', note: 'Feeling unwell, going home');
    await notifier.fetch();

    final s = container.read(currentShiftProvider)!;
    expect(s.earlyEndApproved, isTrue);
    expect(s.earlyEndPending, isFalse);
  });

  test('a completed shift status is honoured over the pending lock', () async {
    final container = makeContainer(
      _SequencedShiftService([_shift(), _shift(status: 'completed')]),
    );
    final notifier = container.read(currentShiftProvider.notifier);

    await notifier.fetch();
    await notifier.requestEarlyEnd(reason: 'Illness', note: 'Feeling unwell, going home');
    await notifier.fetch();

    final s = container.read(currentShiftProvider)!;
    expect(s.status, 'completed');
    expect(s.earlyEndPending, isFalse);
  });
}
