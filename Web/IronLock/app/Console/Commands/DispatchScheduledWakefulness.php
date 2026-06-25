<?php

namespace App\Console\Commands;

use App\Domains\Shifts\Models\Shift;
use App\Domains\Wakefulness\Models\WakefulnessCheck;
use App\Domains\Wakefulness\Services\WakefulnessService;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fire online wakefulness challenges at their provisioned schedule marks
 * (spec §9.4.1).
 *
 * Each active shift carries a randomised schedule (built at start) of challenge
 * times. This runs every minute and, for each active shift, dispatches an
 * ONLINE challenge when a schedule mark has come due and has not already fired.
 * The app fires the matching OFFLINE local notification at the same mark, so the
 * guard is challenged on the same cadence whether or not the device is online.
 *
 * Guardrails:
 *  - A shift with a still-PENDING check is skipped, so challenges never stack.
 *  - A mark counts as fired once a check exists for the shift dated at/after it
 *    (minus a one-run tolerance), so it never double-fires.
 *  - Only the latest due-but-unfired mark is fired per run; stale marks the
 *    server slept through are not spammed out one-by-one.
 *  - A comms-interrupted guard (stale/absent GPS ping) is skipped: an online
 *    push can't reach an offline device, and the app's own offline TOTP
 *    challenge covers them. This is what stops a guard in a dead-signal zone
 *    from collecting a phantom challenge every cycle (which the sweep would
 *    then escalate). On reconnection the dispatcher fires a single fresh
 *    challenge for the latest due mark.
 */
class DispatchScheduledWakefulness extends Command
{
    /** Tolerance, in seconds, when matching an existing check to a mark. */
    private const MATCH_TOLERANCE_SECONDS = 60;

    protected $signature = 'wakefulness:dispatch
                            {--force : Fire the latest due mark on every active shift, ignoring the schedule due-time}';

    protected $description = 'Dispatch online wakefulness challenges at their provisioned schedule marks';

    public function handle(WakefulnessService $wakefulness): int
    {
        $now = Carbon::now();

        $shifts = Shift::with('assignedGuard')
            ->where('status', Shift::STATUS_ACTIVE)
            ->get();

        $fired = 0;
        $skippedOffline = 0;

        foreach ($shifts as $shift) {
            if (!$shift->assignedGuard) {
                continue;
            }

            // Don't challenge a guard we can't reach: an online push won't land
            // on an offline device, and the sweep would only escalate the
            // resulting silence into a false CRITICAL alert. The app's offline
            // TOTP challenge keeps them covered meanwhile.
            if ($wakefulness->guardIsOffline($shift->guard_id)) {
                $skippedOffline++;
                continue;
            }

            // Never stack: skip a shift that already has an unanswered challenge.
            $hasPending = WakefulnessCheck::where('shift_id', $shift->id)
                ->whereNull('result')
                ->exists();
            if ($hasPending) {
                continue;
            }

            $schedule = is_array($shift->wakefulness_schedule) ? $shift->wakefulness_schedule : [];
            if (empty($schedule) && !$this->option('force')) {
                continue;
            }

            // The latest mark that is due (<= now) and not yet fired.
            $dueMark = null;
            foreach ($schedule as $markIso) {
                $mark = $this->parse($markIso);
                if (!$mark || $mark->greaterThan($now)) {
                    continue;
                }

                $alreadyFired = WakefulnessCheck::where('shift_id', $shift->id)
                    ->where('scheduled_at', '>=', $mark->copy()->subSeconds(self::MATCH_TOLERANCE_SECONDS))
                    ->exists();

                if (!$alreadyFired && ($dueMark === null || $mark->greaterThan($dueMark))) {
                    $dueMark = $mark;
                }
            }

            if ($dueMark === null && !$this->option('force')) {
                continue;
            }

            try {
                $wakefulness->dispatchOnlineCheck($shift, WakefulnessCheck::TYPE_SCHEDULED);
                $fired++;
            } catch (\Throwable $e) {
                $this->warn("Failed to dispatch wakefulness check for shift {$shift->id}: {$e->getMessage()}");
            }
        }

        $this->info("Dispatched {$fired} wakefulness challenge(s); skipped {$skippedOffline} offline guard(s).");

        return self::SUCCESS;
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
}
