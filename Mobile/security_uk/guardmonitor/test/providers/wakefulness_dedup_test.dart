import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:guardmonitor/providers/wakefulness_provider.dart';

/// Locks in the cross-path dedup + server-anchored countdown added for the
/// online (push) wakefulness path. The dedup is what stops the FCM push and the
/// local TOTP scheduler both raising a challenge for the same check (H2); the
/// issued_at anchoring stops a late push handing back a full fresh window (H3).
void main() {
  late ProviderContainer container;
  WakefulnessNotifier notifier() =>
      container.read(wakefulnessProvider.notifier);

  setUp(() => container = ProviderContainer());
  tearDown(() => container.dispose());

  test('a second challenge for the same check_id is ignored', () {
    notifier().trigger('chk-1', '1234');
    expect(container.read(wakefulnessProvider).status,
        WakefulnessStatus.challenge);

    // Guard answers and the overlay resets back to idle…
    notifier().reset();
    expect(
        container.read(wakefulnessProvider).status, WakefulnessStatus.idle);

    // …then a late duplicate push for the SAME check arrives — must not
    // re-raise the (already handled) challenge.
    notifier().trigger('chk-1', '1234');
    expect(
        container.read(wakefulnessProvider).status, WakefulnessStatus.idle);
  });

  test('a different check_id still raises a challenge', () {
    notifier().trigger('chk-1', '1234');
    notifier().reset();
    notifier().trigger('chk-2', '5678');
    expect(container.read(wakefulnessProvider).status,
        WakefulnessStatus.challenge);
    expect(container.read(wakefulnessProvider).code, '5678');
  });

  test('clearHistory lets a recycled id be challenged again', () {
    notifier().trigger('chk-1', '1234');
    notifier().reset();
    notifier().clearHistory();
    notifier().trigger('chk-1', '1234');
    expect(container.read(wakefulnessProvider).status,
        WakefulnessStatus.challenge);
  });

  test('issued_at shortens the countdown by the elapsed server time', () {
    final issued = DateTime.now().toUtc().subtract(const Duration(seconds: 20));
    notifier().trigger('chk-1', '1234', responseSeconds: 60, issuedAt: issued);
    final s = container.read(wakefulnessProvider);
    // ~40s should remain (60 - 20); allow a little slack for clock granularity.
    expect(s.secondsRemaining, lessThanOrEqualTo(41));
    expect(s.secondsRemaining, greaterThanOrEqualTo(38));
  });

  test('an already-expired issued_at raises no challenge', () {
    final issued = DateTime.now().toUtc().subtract(const Duration(seconds: 90));
    notifier().trigger('chk-1', '1234', responseSeconds: 60, issuedAt: issued);
    expect(
        container.read(wakefulnessProvider).status, WakefulnessStatus.idle);
  });
}
