import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:guardmonitor/services/challenge_queue.dart';

/// The queue exists to stop a wakefulness overlay and a photo screen racing for
/// the screen when both fire in the same poll/push burst. These pin the one
/// invariant that guarantees it: only ONE presenter runs at a time, and the rest
/// wait their turn, in order.
void main() {
  group('ChallengeQueue', () {
    test('runs a single presenter immediately', () async {
      final q = ChallengeQueue();
      var ran = false;
      q.enqueue(() async => ran = true);
      await Future<void>.delayed(Duration.zero);
      expect(ran, isTrue);
      expect(q.isBusy, isFalse);
      expect(q.pending, 0);
    });

    test('does NOT start the second until the first completes', () async {
      final q = ChallengeQueue();
      final first = Completer<void>();
      final order = <String>[];

      q.enqueue(() async {
        order.add('first-start');
        await first.future;
        order.add('first-end');
      });
      q.enqueue(() async => order.add('second-start'));

      // First is in flight; second must be waiting, not started.
      await Future<void>.delayed(Duration.zero);
      expect(order, ['first-start']);
      expect(q.isBusy, isTrue);
      expect(q.pending, 1);

      // Let the first finish — the second then runs.
      first.complete();
      await Future<void>.delayed(Duration.zero);
      expect(order, ['first-start', 'first-end', 'second-start']);
      expect(q.isBusy, isFalse);
    });

    test('presents in FIFO order', () async {
      final q = ChallengeQueue();
      final order = <int>[];
      for (var i = 0; i < 5; i++) {
        q.enqueue(() async {
          await Future<void>.delayed(const Duration(milliseconds: 1));
          order.add(i);
        });
      }
      await Future<void>.delayed(const Duration(milliseconds: 20));
      expect(order, [0, 1, 2, 3, 4]);
    });

    test('a throwing presenter does not wedge the queue', () async {
      final q = ChallengeQueue();
      final order = <String>[];
      q.enqueue(() async {
        order.add('boom');
        throw StateError('presenter failed');
      });
      q.enqueue(() async => order.add('after'));

      await Future<void>.delayed(Duration.zero);
      expect(order, ['boom', 'after']);
      expect(q.isBusy, isFalse);
    });

    test('clear() drops queued presenters but not the running one', () async {
      final q = ChallengeQueue();
      final first = Completer<void>();
      final order = <String>[];

      q.enqueue(() async {
        order.add('first-start');
        await first.future;
        order.add('first-end');
      });
      q.enqueue(() async => order.add('second'));

      await Future<void>.delayed(Duration.zero);
      expect(q.pending, 1);

      q.clear(); // drops the queued second, first keeps running
      expect(q.pending, 0);

      first.complete();
      await Future<void>.delayed(Duration.zero);
      expect(order, ['first-start', 'first-end']); // 'second' never ran
    });
  });
}
