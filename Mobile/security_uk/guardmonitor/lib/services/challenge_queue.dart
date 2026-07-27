/// Serialises full-screen "challenge" presentations (a wakefulness code overlay,
/// a photo-capture screen) so two never race for the screen at once.
///
/// The bug this fixes: a manual welfare check and a manual photo request can land
/// in the *same* poll/push burst. Each was presented independently — wakefulness
/// via `showDialog` (a dialog route + barrier), photo via `Navigator.push` (a
/// page route) — with no coordination, so the two routes stacked and one dismissed
/// (or stranded a barrier over) the other. Funnelling both through this one FIFO
/// gate means the second waits for the first to close, then presents.
///
/// Pure Dart (no Flutter/Riverpod) so it's unit-testable. Each entry is an async
/// "presenter" that completes when its route closes (e.g. `showDialog(...)` /
/// `Navigator.push(...)` — both return a Future that resolves on pop).
class ChallengeQueue {
  final _queue = <Future<void> Function()>[];
  bool _busy = false;

  /// True while a presenter is on screen.
  bool get isBusy => _busy;

  /// Presenters waiting behind the current one.
  int get pending => _queue.length;

  /// Enqueue a presenter and pump. If nothing is showing it presents right away;
  /// otherwise it waits its turn. Returns immediately (presentation is async).
  void enqueue(Future<void> Function() present) {
    _queue.add(present);
    // Fire-and-forget: the pump drives the chain and each presenter owns its own
    // errors, so a throw can't wedge the queue (see [_pump]).
    _pump();
  }

  /// Drops any not-yet-presented entries (e.g. on shift end / sign-out). Does not
  /// affect a presenter already on screen — that route closes on its own.
  void clear() => _queue.clear();

  Future<void> _pump() async {
    if (_busy || _queue.isEmpty) return;
    _busy = true;
    final present = _queue.removeAt(0);
    try {
      await present();
    } catch (_) {
      // A presenter that throws must not wedge the queue — swallow and continue
      // so the next challenge still gets its turn.
    } finally {
      _busy = false;
      if (_queue.isNotEmpty) {
        // Present the next one. Not awaited: this call is itself inside the
        // finished presenter's frame, and we want the queue to keep draining.
        _pump();
      }
    }
  }
}
