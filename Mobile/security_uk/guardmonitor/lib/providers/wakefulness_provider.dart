import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'app_providers.dart';
import '../services/wakefulness_service.dart';

enum WakefulnessStatus { idle, challenge, success, failed }

class WakefulnessState {
  const WakefulnessState({
    this.status = WakefulnessStatus.idle,
    this.checkId = '',
    this.code = '',
    this.entry = '',
    this.secondsRemaining = 10,
    this.startedAt,
  });

  final WakefulnessStatus status;
  final String checkId;
  final String code;
  final String entry;
  final int secondsRemaining;
  final DateTime? startedAt;

  WakefulnessState copyWith({
    WakefulnessStatus? status,
    String? checkId,
    String? code,
    String? entry,
    int? secondsRemaining,
    DateTime? startedAt,
  }) =>
      WakefulnessState(
        status: status ?? this.status,
        checkId: checkId ?? this.checkId,
        code: code ?? this.code,
        entry: entry ?? this.entry,
        secondsRemaining: secondsRemaining ?? this.secondsRemaining,
        startedAt: startedAt ?? this.startedAt,
      );
}

class WakefulnessNotifier extends Notifier<WakefulnessState> {
  @override
  WakefulnessState build() => const WakefulnessState();

  /// [checkId] and [code] are issued by the server (delivered via the
  /// `/welfare/pending` poll). The code is shown on screen for the guard to
  /// read and type back — it's an attentiveness check, not a secret — so the
  /// pass/fail outcome is already known locally as soon as they submit.
  void trigger(String checkId, String code) {
    state = WakefulnessState(
      status: WakefulnessStatus.challenge,
      checkId: checkId,
      code: code,
      secondsRemaining: 10,
      startedAt: DateTime.now(),
    );
  }

  void addDigit(String digit) {
    if (state.status != WakefulnessStatus.challenge) return;
    if (state.entry.length >= 4) return;
    state = state.copyWith(entry: state.entry + digit);
  }

  void deleteDigit() {
    if (state.entry.isEmpty) return;
    state = state.copyWith(
      entry: state.entry.substring(0, state.entry.length - 1),
    );
  }

  void tick() {
    if (state.status != WakefulnessStatus.challenge) return;
    final remaining = state.secondsRemaining - 1;
    if (remaining <= 0) {
      final checkId = state.checkId;
      final entry = state.entry;
      state = state.copyWith(
          status: WakefulnessStatus.failed, secondsRemaining: 0);
      ref.read(shiftProvider.notifier).recordWelfareCheck(passed: false);
      _respond(checkId, entry);
    } else {
      state = state.copyWith(secondsRemaining: remaining);
    }
  }

  /// Stays synchronous so the overlay can read the result immediately after
  /// calling it — [_respond] still reports the outcome to the server in the
  /// background for the authoritative record, without blocking the UI.
  void submit() {
    final checkId = state.checkId;
    final entry = state.entry;
    if (entry == state.code) {
      state = state.copyWith(status: WakefulnessStatus.success);
      ref.read(shiftProvider.notifier).recordWelfareCheck(passed: true);
    } else {
      state = state.copyWith(status: WakefulnessStatus.failed, entry: '');
      ref.read(shiftProvider.notifier).recordWelfareCheck(passed: false);
    }
    _respond(checkId, entry);
  }

  void reset() => state = const WakefulnessState();

  void _respond(String checkId, String code) async {
    if (checkId.isEmpty) return;
    try {
      await ref.read(wakefulnessServiceProvider).respond(checkId, code);
    } catch (_) {}
  }
}

final wakefulnessProvider =
    NotifierProvider<WakefulnessNotifier, WakefulnessState>(WakefulnessNotifier.new);
