<?php

namespace App\Console\Commands;

use App\Domains\Wakefulness\Models\WakefulnessCheck;
use App\Domains\Wakefulness\Services\WakefulnessService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Sweep wakefulness challenges the guard never answered (spec §9.5).
 *
 * An ONLINE challenge must be answered within the response window
 * (config wakefulness_response_seconds, 60s) plus a small network grace. A
 * check still PENDING past that deadline is failed: result → FAILED, a
 * WAKEFULNESS_FAILED audit event is written, and a CRITICAL GUARD_UNRESPONSIVE
 * alert is raised so a supervisor dispatches a welfare check. Runs every minute.
 *
 * Only ONLINE checks time out here; OFFLINE checks have no live deadline — they
 * are validated cryptographically when the app reconnects and replays them.
 *
 * The CRITICAL alert only fires for a genuine sleeping-guard case. It is
 * SUPPRESSED (failed for audit, no supervisor paged) in two situations where
 * silence isn't proof the guard ignored the challenge:
 *   - COMMS_INTERRUPTED — the guard's GPS ping is stale/absent, so the device is
 *     offline (master spec Scenario 4). The dispatcher already skips offline
 *     guards, so this is mostly a safety net for a guard who dropped signal after
 *     a challenge was dispatched.
 *   - PUSH_UNDELIVERED — the guard is online (fresh GPS) but never confirmed the
 *     challenge push arrived (`delivered_at` is null). The push was lost in
 *     transit, not ignored — paging for it would be a false alarm (Phase 6 push
 *     reliability; closes the Phase 5 dropped-push gap).
 *   - STALE_CATCHUP — the deadline lapsed more than catchup_staleness_seconds ago
 *     (default 180s). The only way a check sits PENDING that long past its
 *     deadline is that this sweep wasn't running (scheduler gap) — normally it
 *     fails a check within ~1 min. Failing it now is correct for the record, but
 *     raising a fresh CRITICAL for a deadline that lapsed minutes ago pages a
 *     supervisor for a non-actionable, historical miss. Recorded for audit, not
 *     paged.
 * Only a guard who is online AND confirmed delivery AND missed within the live
 * window is escalated as NO_RESPONSE.
 *
 * The WAKEFULNESS_FAILED event is anchored to the check's real deadline (not the
 * sweep time), so a catch-up miss reads truthfully on the timeline instead of
 * appearing minutes after the challenge.
 */
class SweepWakefulnessTimeouts extends Command
{
    protected $signature = 'wakefulness:timeout-sweep';

    protected $description = 'Fail wakefulness challenges not answered within the response window and raise a CRITICAL alert';

    public function handle(WakefulnessService $wakefulness): int
    {
        $now = Carbon::now();
        $deadlineSeconds = (int) config('ironlock.wakefulness_response_seconds', 60)
            + (int) config('ironlock.wakefulness_deadline_grace_seconds', 5);
        $cutoff = $now->copy()->subSeconds($deadlineSeconds);
        $staleSeconds = (int) config('ironlock.catchup_staleness_seconds', 180);

        $checks = WakefulnessCheck::with(['shift.assignedGuard', 'assignedGuard'])
            ->whereNull('result')
            ->where('online_or_offline', WakefulnessCheck::MODE_ONLINE)
            ->where('scheduled_at', '<', $cutoff)
            ->get();

        $failed = 0;
        $suppressed = 0;

        foreach ($checks as $check) {
            // Atomic transition guards against a late response racing the sweep:
            // only the row still PENDING (null result) is flipped to FAILED.
            $affected = WakefulnessCheck::where('id', $check->id)
                ->whereNull('result')
                ->update([
                    'result' => WakefulnessCheck::RESULT_FAILED,
                    'server_received_at' => $now,
                ]);

            if ($affected !== 1) {
                continue;
            }

            // Reflect the persisted transition on the in-memory model so the
            // escalation event/alert carry the right timestamp and result.
            $check->result = WakefulnessCheck::RESULT_FAILED;
            $check->server_received_at = $now;

            // The real deadline this check was meant to be judged against, and how
            // long after it the sweep actually got here. Under a normal (every
            // minute) sweep, detectedLateBy is small; a large value means the
            // scheduler stalled and this is a catch-up, not a live miss.
            $deadlineAt = $check->scheduled_at->copy()->addSeconds($deadlineSeconds);
            $detectedLateBy = $deadlineAt->lessThan($now) ? $deadlineAt->diffInSeconds($now) : 0;

            // Decide whether this missed check is a genuine unresponsive guard or
            // a suppressible delivery/connectivity/catch-up gap (see class docblock).
            if ($wakefulness->guardIsOffline($check->guard_id)) {
                // Offline device — connectivity gap, not a sleeping guard.
                $reason = 'COMMS_INTERRUPTED';
                $raiseAlert = false;
            } elseif ($check->delivered_at === null) {
                // Online, but the app never confirmed the push — lost in transit.
                $reason = 'PUSH_UNDELIVERED';
                $raiseAlert = false;
            } elseif ($detectedLateBy > $staleSeconds) {
                // Deadline lapsed long ago — the sweep wasn't running (scheduler
                // gap). Record the miss, but don't page for a historical deadline.
                $reason = 'STALE_CATCHUP';
                $raiseAlert = false;
            } else {
                // Online, confirmed delivered, missed within the live window → alarm.
                $reason = 'NO_RESPONSE';
                $raiseAlert = true;
            }

            // Anchor the failure to the true deadline (#8a) and record how late the
            // sweep detected it, so the audit trail is honest about catch-ups.
            $wakefulness->escalateUnresponsive($check, $reason, $raiseAlert, $deadlineAt, [
                'deadline_at' => $deadlineAt->toISOString(),
                'detected_late_by_seconds' => $detectedLateBy,
            ]);

            $raiseAlert ? $failed++ : $suppressed++;
        }

        $this->info("Failed {$failed} unanswered wakefulness check(s) (alerted); suppressed {$suppressed} (comms-interrupted / push-undelivered / stale-catchup).");

        return self::SUCCESS;
    }
}
