<?php

namespace App\Domains\Wakefulness\Services;

use App\Domains\Alerts\Services\AlertService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Guards\Models\Guard;
use App\Domains\Notifications\Services\FcmService;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use App\Domains\Wakefulness\Models\WakefulnessCheck;
use App\Domains\Wakefulness\Support\Totp;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * WakefulnessService — the Code-Challenge Protocol pipeline (spec §9).
 *
 * The server is the only authority on pass/fail. A challenge is dispatched at a
 * provisioned schedule mark; the guard must transcribe the on-screen 4-digit
 * code within the response window (config wakefulness_response_seconds, 60s) or
 * a CRITICAL GUARD_UNRESPONSIVE alert fires.
 *
 *   ONLINE  — the server picks a random 4-digit code, stores it on the check and
 *             pushes it (FCM). Validation compares the submitted code to the
 *             stored challenge_code and enforces the response deadline.
 *   OFFLINE — the app computed TOTP(seed, window) locally and the guard
 *             transcribed it. On reconnection the result is submitted with its
 *             window reference; the server re-derives the TOTP from the shift
 *             seed and confirms/escalates retroactively (spec §9.4). The offline
 *             *transport* (bulk sync queue) is Phase 7; Phase 5 validates a
 *             single offline response.
 *
 * All server timestamps are set explicitly in PHP/UTC — never DB
 * CURRENT_TIMESTAMP (the project's DB-session-timezone gotcha).
 */
class WakefulnessService
{
    public function __construct(
        private readonly AlertService $alerts,
        private readonly FcmService $fcm,
    ) {}

    /**
     * Dispatch an ONLINE wakefulness challenge for an active shift: create the
     * pending check with a fresh random code, log it, and push it to the device.
     * $requestType records whether it was the auto-dispatcher (default) or a
     * supervisor's manual request. Returns the created check (null if the shift
     * has no guard).
     */
    public function dispatchOnlineCheck(Shift $shift, string $requestType = WakefulnessCheck::TYPE_SCHEDULED): ?WakefulnessCheck
    {
        $guard = $shift->assignedGuard;
        if (!$guard) {
            return null;
        }

        $now = Carbon::now();
        $code = str_pad((string) random_int(0, 9999), $this->digits(), '0', STR_PAD_LEFT);

        
        $check = WakefulnessCheck::create([
            'id' => (string) Str::uuid(),
            'shift_id' => $shift->id,
            'guard_id' => $guard->id,
            'challenge_code' => $code,
            'totp_window_reference' => null,
            'scheduled_at' => $now,
            'result' => null, // PENDING
            'is_offline' => false,
            'online_or_offline' => WakefulnessCheck::MODE_ONLINE,
            'request_type' => $requestType,
        ]);

        $this->logEvent($shift, 'WAKEFULNESS_CHALLENGE', [
            'check_id' => $check->id,
            'mode' => WakefulnessCheck::MODE_ONLINE,
            'request_type' => $requestType,
            'response_seconds' => $this->responseSeconds(),
        ]);

        $this->pushChallenge($guard, $check, $code);

        return $check;
    }

    /**
     * Re-derive the TOTP code the app would have shown for a given window — the
     * server-side authority for validating an offline response (spec §9.4).
     */
    public function recomputeTotp(string $seed, int $window): string
    {
        return Totp::codeForWindow($seed, $window, $this->digits());
    }

    /**
     * Validate a guard's response to a challenge and record the outcome.
     *
     * @param  Guard       $guard            the authenticated guard
     * @param  string      $checkId          the pending check being answered
     * @param  string      $code             the transcribed code
     * @param  string|null $respondedAt      device-reported response time (audit)
     * @param  int|null    $windowReference  offline only: the TOTP window the app used
     * @return array{result: 'PASSED'|'FAILED', reason: ?string, check: ?WakefulnessCheck}
     */
    public function respond(
        Guard $guard,
        string $checkId,
        string $code,
        ?string $respondedAt = null,
        ?int $windowReference = null
    ): array {
        $serverReceivedAt = Carbon::now();

        /** @var WakefulnessCheck|null $check */
        $check = WakefulnessCheck::where('id', $checkId)
            ->where('guard_id', $guard->id)
            ->first();

        if (!$check) {
            return ['result' => 'FAILED', 'reason' => 'CHECK_NOT_FOUND', 'check' => null];
        }

        // Idempotent: a check already resolved just echoes its recorded outcome
        // (e.g. the timeout sweep won the race, or a duplicate submit).
        if (!$check->isPending()) {
            return [
                'result' => $check->result === WakefulnessCheck::RESULT_CONFIRMED ? 'PASSED' : 'FAILED',
                'reason' => 'ALREADY_RESOLVED',
                'check' => $check,
            ];
        }

        $shift = $check->shift;
        $isOffline = $check->online_or_offline === WakefulnessCheck::MODE_OFFLINE
            || (bool) $check->is_offline
            || $windowReference !== null;

        if ($isOffline) {
            // Offline: re-derive the TOTP from the shift seed for the claimed
            // window and compare. Judged by the cryptographic match, not a live
            // deadline — the window itself proves when the code was valid.
            $window = $windowReference ?? $check->totp_window_reference;
            $seed = $shift?->totp_seed;
            $expected = ($seed && $window !== null) ? $this->recomputeTotp($seed, (int) $window) : null;
            $correct = $expected !== null && hash_equals($expected, str_pad($code, $this->digits(), '0', STR_PAD_LEFT));

            $check->totp_window_reference = $window;
            $check->is_offline = true;
            $check->online_or_offline = WakefulnessCheck::MODE_OFFLINE;
            $reason = $correct ? null : 'OFFLINE_CODE_MISMATCH';
        } else {
            // Online: compare to the stored challenge and enforce the deadline.
            $deadline = $check->scheduled_at
                ->copy()
                ->addSeconds($this->responseSeconds() + $this->graceSeconds());
            $onTime = $serverReceivedAt->lessThanOrEqualTo($deadline);
            $codeMatches = hash_equals(
                (string) $check->challenge_code,
                str_pad($code, $this->digits(), '0', STR_PAD_LEFT)
            );
            $correct = $codeMatches && $onTime;
            $reason = $codeMatches ? ($onTime ? null : 'RESPONSE_LATE') : 'WRONG_CODE';
        }

        // response_time_seconds is audit-only (NOT the pass/fail authority) and
        // the column is decimal(4,2), so cap it at 99.99.
        $elapsed = $check->scheduled_at ? $check->scheduled_at->diffInSeconds($serverReceivedAt) : null;
        $responseTime = $elapsed === null ? null : min(99.99, round($elapsed, 2));

        $check->submitted_code = $code;
        $check->responded_at = $respondedAt ? $this->parse($respondedAt) : $serverReceivedAt;
        $check->server_received_at = $serverReceivedAt;
        $check->response_time_seconds = $responseTime;
        $check->result = $correct ? WakefulnessCheck::RESULT_CONFIRMED : WakefulnessCheck::RESULT_FAILED;
        $check->save();

        if ($correct) {
            $this->logEvent($shift, 'WAKEFULNESS_CONFIRMED', [
                'check_id' => $check->id,
                'mode' => $check->online_or_offline,
                'response_time_seconds' => $responseTime,
            ]);
            return ['result' => 'PASSED', 'reason' => null, 'check' => $check];
        }

        // An OFFLINE answer is replayed on reconnect, after its window has already
        // closed — record the failure for audit (WAKEFULNESS_FAILED is written
        // regardless below), but do NOT raise a live CRITICAL welfare-check alert
        // retroactively for a challenge that is already over (Phase 7 §7.3, "no
        // retroactive alerts"; the guard is back online by the time we see this).
        // A genuine *live* miss is still escalated: the online timeout sweep pages
        // unanswered online challenges, and an online failure here keeps alerting.
        $this->escalateUnresponsive($check, $reason ?? 'FAILED', raiseAlert: !$isOffline);

        return ['result' => 'FAILED', 'reason' => $reason, 'check' => $check];
    }

    /**
     * Record an OFFLINE wakefulness result flushed on reconnect (spec §9.4).
     *
     * Unlike respond(), no server check row exists yet: while the guard was
     * offline the dispatcher skipped them (an online push can't reach an offline
     * device), so the challenge lived only on the device — a local notification
     * fired at the schedule mark and the app computed the TOTP locally. This
     * MATERIALISES the check from the flushed result, mirroring the offline-photo
     * path (PhotoVerificationService::resolveRequest), so an offline wakefulness
     * verification — pass OR fail — always lands in the audit trail, the timeline
     * and the reports. Without this the result had nowhere to go (respond() would
     * 404 CHECK_NOT_FOUND) and was silently dropped.
     *
     * Judged cryptographically: the server re-derives the TOTP for the claimed
     * window from the shift seed. A wrong code is recorded FAILED but raises NO
     * live alert — the window is long closed and the guard is back online by the
     * time we see this (Phase 7 §7.3, "no retroactive alerts"). Idempotent: a
     * re-flushed window echoes the first recorded outcome (replay-safe).
     *
     * @return array{result:'PASSED'|'FAILED', reason:?string, check:?WakefulnessCheck}
     */
    public function recordOfflineResult(
        Guard $guard,
        Shift $shift,
        int $windowReference,
        string $code,
        ?string $scheduledAt = null,
        ?string $respondedAt = null
    ): array {
        $seed = $shift->totp_seed;
        if (empty($seed)) {
            // The shift was never provisioned with a wakefulness seed — there is
            // nothing to validate against. Not a guard failure; a 409 for the app.
            return ['result' => 'FAILED', 'reason' => 'SEED_UNAVAILABLE', 'check' => null];
        }

        // Idempotent: the same offline window flushed twice echoes the first
        // recorded outcome — replay-safe and order-insensitive (Phase 7 §7.3).
        $existing = WakefulnessCheck::where('shift_id', $shift->id)
            ->where('guard_id', $guard->id)
            ->where('totp_window_reference', $windowReference)
            ->where('online_or_offline', WakefulnessCheck::MODE_OFFLINE)
            ->first();
        if ($existing) {
            return [
                'result' => $existing->result === WakefulnessCheck::RESULT_CONFIRMED ? 'PASSED' : 'FAILED',
                'reason' => 'ALREADY_RESOLVED',
                'check' => $existing,
            ];
        }

        $serverReceivedAt = Carbon::now();
        $expected = $this->recomputeTotp($seed, $windowReference);
        $correct = hash_equals($expected, str_pad($code, $this->digits(), '0', STR_PAD_LEFT));

        // Anchor the challenge to when it actually fired on-device: the app's
        // reported mark, else the window's own start instant (window * period).
        $scheduled = $this->parse($scheduledAt)
            ?? Carbon::createFromTimestamp($windowReference * $this->totpPeriod());
        $responded = $this->parse($respondedAt) ?? $serverReceivedAt;

        // Audit-only elapsed time (NOT the pass/fail authority); column is
        // decimal(4,2) so cap at 99.99 exactly as respond() does.
        $responseTime = min(99.99, round($scheduled->diffInSeconds($responded), 2));

        $check = WakefulnessCheck::create([
            'id' => (string) Str::uuid(),
            'shift_id' => $shift->id,
            'guard_id' => $guard->id,
            'challenge_code' => $expected,
            'submitted_code' => $code,
            'totp_window_reference' => $windowReference,
            'scheduled_at' => $scheduled,
            'responded_at' => $responded,
            'server_received_at' => $serverReceivedAt,
            'response_time_seconds' => $responseTime,
            'result' => $correct ? WakefulnessCheck::RESULT_CONFIRMED : WakefulnessCheck::RESULT_FAILED,
            'is_offline' => true,
            'online_or_offline' => WakefulnessCheck::MODE_OFFLINE,
            'request_type' => WakefulnessCheck::TYPE_SCHEDULED,
        ]);

        // Backfill the challenge into the timeline too — the online path logs
        // WAKEFULNESS_CHALLENGE at dispatch; offline it fired on-device, so we
        // record it here at its actual mark so the trail isn't missing the ask.
        $this->logEvent($shift, 'WAKEFULNESS_CHALLENGE', [
            'check_id' => $check->id,
            'mode' => WakefulnessCheck::MODE_OFFLINE,
            'request_type' => WakefulnessCheck::TYPE_SCHEDULED,
            'backfilled' => true,
        ]);

        if ($correct) {
            $this->logEvent($shift, 'WAKEFULNESS_CONFIRMED', [
                'check_id' => $check->id,
                'mode' => WakefulnessCheck::MODE_OFFLINE,
                'response_time_seconds' => $responseTime,
                'backfilled' => true,
            ]);

            return ['result' => 'PASSED', 'reason' => null, 'check' => $check];
        }

        // Failed offline replay — WAKEFULNESS_FAILED audit event only, never a
        // retroactive live CRITICAL alert (raiseAlert:false).
        $this->escalateUnresponsive($check, 'OFFLINE_CODE_MISMATCH', raiseAlert: false);

        return ['result' => 'FAILED', 'reason' => 'OFFLINE_CODE_MISMATCH', 'check' => $check];
    }

    /**
     * Record that the guard's app received an ONLINE challenge push (Phase 6
     * push reliability). Stamps `delivered_at` once, so the timeout sweep can
     * tell "delivered but ignored" (→ CRITICAL) from "never delivered" (→
     * suppressed PUSH_UNDELIVERED). Idempotent and best-effort: a repeat call, an
     * already-resolved check, or an offline check is a harmless no-op success —
     * the app should fire-and-forget this.
     *
     * @return array{ok: bool, reason: ?string}
     */
    public function acknowledgeDelivery(Guard $guard, string $checkId): array
    {
        /** @var WakefulnessCheck|null $check */
        $check = WakefulnessCheck::where('id', $checkId)
            ->where('guard_id', $guard->id)
            ->first();

        if (!$check) {
            return ['ok' => false, 'reason' => 'CHECK_NOT_FOUND'];
        }

        // Only stamp the first confirmation, and only for online challenges
        // (offline TOTP challenges are never server-delivered).
        if ($check->delivered_at === null
            && $check->online_or_offline === WakefulnessCheck::MODE_ONLINE) {
            $check->delivered_at = Carbon::now();
            $check->save();
        }

        return ['ok' => true, 'reason' => null];
    }

    /**
     * Whether the guard is currently comms-interrupted (offline). Delegates to
     * the GPS phase's single source of truth, GuardLocation::isCommsInterrupted()
     * — the same definition that greys the guard out on the live map — so the two
     * can never diverge (a guard shown offline is never simultaneously paged as
     * unresponsive). No live position row at all → treated as offline (fail safe:
     * never alarm a guard we cannot confirm is reachable). Used to distinguish
     * "device unreachable" from "guard ignored a challenge" (master spec
     * Scenario 4).
     */
    public function guardIsOffline(string $guardId): bool
    {
        $location = GuardLocation::where('guard_id', $guardId)->first();

        return !$location || $location->isCommsInterrupted();
    }

    /**
     * Record a failed/unanswered check: the immutable WAKEFULNESS_FAILED audit
     * event plus (when $raiseAlert) a CRITICAL GUARD_UNRESPONSIVE alert (spec
     * §9.5). Best-effort — a logging/alerting failure must never break the
     * calling path.
     *
     * $raiseAlert is false when the timeout-sweep determines the guard was
     * comms-interrupted: the missed challenge is recorded for audit, but no
     * supervisor is paged for what is a connectivity gap, not a sleeping guard.
     */
    public function escalateUnresponsive(WakefulnessCheck $check, string $reason, bool $raiseAlert = true): void
    {
        $shift = $check->shift;
        $guard = $check->assignedGuard;
        $occurredAt = ($check->server_received_at ?? Carbon::now());

        $this->logEvent($shift, 'WAKEFULNESS_FAILED', [
            'check_id' => $check->id,
            'mode' => $check->online_or_offline,
            'reason' => $reason,
            'alerted' => $raiseAlert,
        ]);

        if (!$raiseAlert) {
            return;
        }

        try {
            $this->alerts->createGuardUnresponsiveAlert(
                $check->guard_id,
                $check->shift_id,
                $guard,
                $reason,
                $occurredAt instanceof Carbon ? $occurredAt->toISOString() : (string) $occurredAt
            );
        } catch (\Throwable $e) {
            Log::warning('Wakefulness: failed to raise GUARD_UNRESPONSIVE alert', [
                'check_id' => $check->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Best-effort push of the online challenge. The code is delivered in the
     * notification body — the guard transcribes it to prove alertness. Delivery
     * is a convenience: the check row already exists and the timeout sweep is
     * the backstop, so a push failure never breaks dispatch.
     */
    private function pushChallenge(Guard $guard, WakefulnessCheck $check, string $code): void
    {
        if (empty($guard->push_token)) {
            return;
        }

        try {
            $this->fcm->sendToDevice(
                $guard->push_token,
                'Wakefulness check',
                "Open the app and enter code {$code} within {$this->responseSeconds()} seconds.",
                [
                    'type' => 'WAKEFULNESS_CHALLENGE',
                    'check_id' => $check->id,
                    'shift_id' => $check->shift_id,
                    'code' => $code,
                    'response_seconds' => $this->responseSeconds(),
                ]
            );
        } catch (\Throwable $e) {
            Log::info('Wakefulness: challenge push skipped', [
                'check_id' => $check->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    private function logEvent(?Shift $shift, string $eventType, array $metadata = []): void
    {
        if (!$shift) {
            return;
        }

        try {
            ShiftEvent::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'event_type' => $eventType,
                'metadata' => $metadata,
                'recorded_at' => Carbon::now(),
                'server_received_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is non-critical to the request path.
        }
    }

    private function parse(?string $value): ?Carbon
    {
        if (empty($value)) {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function responseSeconds(): int
    {
        return (int) config('ironlock.wakefulness_response_seconds', 60);
    }

    private function graceSeconds(): int
    {
        return (int) config('ironlock.wakefulness_deadline_grace_seconds', 5);
    }

    private function digits(): int
    {
        return (int) config('ironlock.totp_digits', 4);
    }

    /** TOTP time-step in seconds (RFC-6238 default 30s), shared with the app. */
    private function totpPeriod(): int
    {
        return (int) config('ironlock.totp_period_seconds', 30);
    }
}
