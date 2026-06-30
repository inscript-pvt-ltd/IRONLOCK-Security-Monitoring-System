import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:guardmonitor/providers/wakefulness_provider.dart';
import 'package:guardmonitor/services/wakefulness_service.dart';

/// A stand-in for the real service so we can pin how the challenge resolves
/// against the *server's* verdict (not just the local code compare).
class _FakeWakefulness extends WakefulnessService {
  _FakeWakefulness({this.verdict = true, this.throwIt = false}) : super(Dio());
  final bool verdict;
  final bool throwIt;

  @override
  Future<bool> respond(
    String checkId,
    String code, {
    int? windowReference,
    bool isOffline = false,
  }) async {
    if (throwIt) throw Exception('network down');
    return verdict;
  }

  @override
  Future<void> confirmReceived(String checkId) async {}
}

void main() {
  ProviderContainer containerWith(_FakeWakefulness fake) => ProviderContainer(
        overrides: [wakefulnessServiceProvider.overrideWithValue(fake)],
      );

  void enter(WakefulnessNotifier n, String code) {
    for (final d in code.split('')) {
      n.addDigit(d);
    }
  }

  group('server verdict reconciliation (#1)', () {
    test('correct code + server PASS → success', () async {
      final c = containerWith(_FakeWakefulness(verdict: true));
      addTearDown(c.dispose);
      final n = c.read(wakefulnessProvider.notifier);
      n.trigger('chk-1', '1234');
      enter(n, '1234');
      await n.submit();
      expect(c.read(wakefulnessProvider).status, WakefulnessStatus.success);
    });

    test('correct code but server REJECTS it → failed, not success', () async {
      // The guard typed the right digits but the server says no (e.g. its window
      // already lapsed / clock skew). The app must NOT tell them they passed.
      final c = containerWith(_FakeWakefulness(verdict: false));
      addTearDown(c.dispose);
      final n = c.read(wakefulnessProvider.notifier);
      n.trigger('chk-1', '1234');
      enter(n, '1234');
      await n.submit();
      expect(c.read(wakefulnessProvider).status, WakefulnessStatus.failed);
    });

    test('correct code + unreachable server → optimistic success (lenient)',
        () async {
      // A connectivity blip must not fail a guard who answered in time.
      final c = containerWith(_FakeWakefulness(throwIt: true));
      addTearDown(c.dispose);
      final n = c.read(wakefulnessProvider.notifier);
      n.trigger('chk-1', '1234');
      enter(n, '1234');
      await n.submit();
      expect(c.read(wakefulnessProvider).status, WakefulnessStatus.success);
    });

    test('wrong code fails instantly without a verifying round-trip', () async {
      final c = containerWith(_FakeWakefulness(verdict: true));
      addTearDown(c.dispose);
      final n = c.read(wakefulnessProvider.notifier);
      n.trigger('chk-1', '1234');
      enter(n, '9999');
      await n.submit();
      expect(c.read(wakefulnessProvider).status, WakefulnessStatus.failed);
    });
  });

  group('minimum-window floor (#4)', () {
    test('a challenge with less than the floor left is not shown', () {
      final c = ProviderContainer();
      addTearDown(c.dispose);
      final n = c.read(wakefulnessProvider.notifier);
      // 60s window issued 56s ago → ~4s left, below kMinResponseSeconds.
      n.trigger('chk-1', '1234',
          responseSeconds: 60,
          issuedAt: DateTime.now().toUtc().subtract(const Duration(seconds: 56)));
      expect(c.read(wakefulnessProvider).status, WakefulnessStatus.idle);
    });

    test('a challenge with more than the floor left is shown', () {
      final c = ProviderContainer();
      addTearDown(c.dispose);
      final n = c.read(wakefulnessProvider.notifier);
      n.trigger('chk-1', '1234',
          responseSeconds: 60,
          issuedAt: DateTime.now().toUtc().subtract(const Duration(seconds: 45)));
      expect(c.read(wakefulnessProvider).status, WakefulnessStatus.challenge);
    });
  });

  group('deadline-anchored countdown (#3)', () {
    test('tick recomputes from the deadline — it does not blindly decrement',
        () {
      final c = ProviderContainer();
      addTearDown(c.dispose);
      final n = c.read(wakefulnessProvider.notifier);
      n.trigger('chk-1', '1234', responseSeconds: 60);
      // Five ticks fired within the same wall-clock second (as a frozen, then
      // resumed, timer might) must not burn five seconds off the clock.
      for (var i = 0; i < 5; i++) {
        n.tick();
      }
      expect(c.read(wakefulnessProvider).secondsRemaining,
          greaterThanOrEqualTo(58));
    });
  });
}
