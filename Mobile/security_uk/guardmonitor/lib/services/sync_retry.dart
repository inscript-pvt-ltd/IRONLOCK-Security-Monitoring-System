import 'dart:math';

import 'package:dio/dio.dart';

import 'photo_service.dart' show PhotoRejectedException;

/// What to do with a queued item after one flush attempt.
enum FlushAction {
  /// The server accepted it (or it was already recorded) — remove from the queue.
  success,

  /// Transient failure (transport / timeout / 5xx) — keep it, back off, retry.
  retry,

  /// Terminal business rejection (bad payload, dead shift, unknown id) —
  /// retrying can never succeed, so drop it.
  drop,
}

/// The verdict for a flush attempt, plus the server business [code] (if any) for
/// logging. Pure data — see [classifyFlush].
class FlushDecision {
  const FlushDecision(this.action, [this.code]);
  final FlushAction action;
  final String? code;

  @override
  String toString() => 'FlushDecision($action${code == null ? '' : ', $code'})';
}

/// Decides the fate of a queued item from the [error] a flush attempt threw
/// (or `null` if it succeeded). This is the single source of truth for the
/// retry table in PHASE_7_FLUTTER_OFFLINE_SYNC.md §4 — pure and unit-testable,
/// no network or DB.
///
/// Rules:
/// - success (no error) → [FlushAction.success]
/// - transport error / timeout / 5xx → [FlushAction.retry]
/// - `ALREADY_RESOLVED` / `NONCE_ALREADY_USED` → [FlushAction.success]
///   (idempotent: the item already landed)
/// - any other 4xx (`VALIDATION_ERROR`, `SHIFT_NOT_ACTIVE`, `CHECK_NOT_FOUND`,
///   `NONCE_NOT_FOUND`, unknown) → [FlushAction.drop]
/// - a non-network error (e.g. a queued photo's file vanished) → [FlushAction.drop]
FlushDecision classifyFlush(Object? error) {
  if (error == null) return const FlushDecision(FlushAction.success);

  // Photos surface a 422 as a typed exception carrying the server reason.
  if (error is PhotoRejectedException) {
    if (_isAlreadyDone(error.reason)) {
      return FlushDecision(FlushAction.success, error.reason);
    }
    return FlushDecision(FlushAction.drop, error.reason);
  }

  if (error is DioException) {
    final status = error.response?.statusCode;
    // No response at all (connect/receive timeout, connection drop) → transient.
    if (status == null) return const FlushDecision(FlushAction.retry);
    // 5xx → server hiccup, worth retrying.
    if (status >= 500) return const FlushDecision(FlushAction.retry);
    if (status >= 400) {
      final code = _extractCode(error.response?.data);
      if (_isAlreadyDone(code)) return FlushDecision(FlushAction.success, code);
      // Every other 4xx is terminal — including unknown codes, so a permanently
      // bad row can never pin the queue.
      return FlushDecision(FlushAction.drop, code);
    }
    // Any other status (1xx/3xx) is unexpected for these endpoints; retry once
    // rather than silently dropping.
    return const FlushDecision(FlushAction.retry);
  }

  // Unknown, non-network error — retrying won't fix it.
  return const FlushDecision(FlushAction.drop);
}

bool _isAlreadyDone(String? code) =>
    code == 'ALREADY_RESOLVED' || code == 'NONCE_ALREADY_USED';

/// Pulls `error.code` out of the standard `{ "error": { "code": ... } }`
/// envelope; tolerant of a missing/odd shape.
String? _extractCode(Object? data) {
  if (data is Map) {
    final error = data['error'];
    if (error is Map) {
      final code = error['code'];
      if (code is String) return code;
    }
    // Some endpoints put a bare `code` at the top level.
    final top = data['code'];
    if (top is String) return top;
  }
  return null;
}

/// Exponential backoff with jitter for a retryable item that has already failed
/// [attempts] times. `delay = min(base · 2^attempts, cap)` plus up to +25%
/// jitter so a fleet reconnecting at once doesn't thunder. [random] is injectable
/// for deterministic tests.
Duration backoffDelay(
  int attempts, {
  Duration base = const Duration(seconds: 2),
  Duration cap = const Duration(minutes: 5),
  Random? random,
}) {
  final shift = attempts.clamp(0, 30);
  final expMs = base.inMilliseconds * (1 << shift);
  final cappedMs = min(expMs, cap.inMilliseconds);
  final jitter = (random ?? _rng).nextDouble() * cappedMs * 0.25;
  return Duration(milliseconds: (cappedMs + jitter).round());
}

final Random _rng = Random();

/// After this many failed attempts a retryable item is dropped, so a row the
/// server keeps 5xx-ing on (or a corrupt payload we misclassify) can't live
/// forever. ~12 attempts spans well over an hour at the capped backoff.
const int kMaxFlushAttempts = 12;
