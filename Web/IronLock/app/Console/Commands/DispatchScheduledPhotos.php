<?php

namespace App\Console\Commands;

use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Photos\Services\PhotoVerificationService;
use App\Domains\Shifts\Models\Shift;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fire verification photo checks at their provisioned schedule marks
 * (Phase 7 Option A; spec §8.2).
 *
 * Each active shift carries a randomised photo schedule (built at start, the
 * same way wakefulness is) of due-times. This runs every minute and, for each
 * active shift, dispatches an ONLINE PHOTO_REQUEST when a schedule mark has come
 * due and has not already fired. The app fires the matching OFFLINE camera
 * capture (against a pre-fetched OFFLINE_POOL nonce) at the same mark, so the
 * guard is checked on the same cadence whether or not the device is online.
 *
 * Unpredictability still holds: the marks are randomised once at provisioning,
 * so the guard cannot anticipate them — the previous per-run probability is
 * superseded by a fixed-but-random schedule (mirrors the wakefulness design).
 *
 * Guardrails (mirror DispatchScheduledWakefulness):
 *  - A shift with a still-PENDING request is skipped, so checks never stack.
 *  - A mark counts as fired once a request exists for the shift dated at/after
 *    it (minus a one-run tolerance), so it never double-fires.
 *  - Only the latest due-but-unfired mark is fired per run; stale marks the
 *    server slept through are not spammed out one-by-one.
 *  - A due mark older than catchup_staleness_seconds (default 180s) is NOT fired
 *    online at all: it came due while the guard/scheduler was offline, where the
 *    app's own offline capture already covered the guard. Firing it online now
 *    would land a surprise request seconds after reconnect (finding #3). The next
 *    future mark still fires normally. --force bypasses this.
 *  - A comms-interrupted guard (stale/absent GPS ping) is skipped: an online
 *    push can't reach an offline device, and the app's own offline capture
 *    covers them. This is what stops a guard in a dead-signal zone from
 *    collecting a phantom request every cycle (which the timeout sweep would
 *    then escalate). On reconnection the dispatcher fires a single fresh request
 *    for the latest due mark.
 */
class DispatchScheduledPhotos extends Command
{
    /** Tolerance, in seconds, when matching an existing request to a mark. */
    private const MATCH_TOLERANCE_SECONDS = 60;

    protected $signature = 'photos:dispatch-scheduled
                            {--force : Fire the latest due mark on every active shift, ignoring the schedule due-time}';

    protected $description = 'Fire verification photo checks at their provisioned schedule marks';

    public function handle(PhotoVerificationService $photos): int
    {
        $now = Carbon::now();
        $staleCutoff = $now->copy()->subSeconds((int) config('ironlock.catchup_staleness_seconds', 180));

        $shifts = Shift::with('assignedGuard')
            ->where('status', Shift::STATUS_ACTIVE)
            ->get();

        $fired = 0;
        $skippedOffline = 0;
        $skippedStale = 0;

        foreach ($shifts as $shift) {
            if (!$shift->assignedGuard) {
                continue;
            }

            // Don't request from a guard we can't reach: an online push won't
            // land on an offline device, and the timeout sweep would only
            // escalate the resulting silence into a false CRITICAL. The app's
            // offline capture keeps them covered meanwhile.
            if ($this->guardIsOffline($shift->guard_id)) {
                $skippedOffline++;
                continue;
            }

            // Never stack: skip a shift that already has an unanswered request.
            $hasPending = PhotoRequest::where('shift_id', $shift->id)
                ->where('status', PhotoRequest::STATUS_PENDING)
                ->exists();
            if ($hasPending) {
                continue;
            }

            $schedule = is_array($shift->photo_schedule) ? $shift->photo_schedule : [];
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

                $alreadyFired = PhotoRequest::where('shift_id', $shift->id)
                    ->where('requested_at', '>=', $mark->copy()->subSeconds(self::MATCH_TOLERANCE_SECONDS))
                    ->exists();

                if (!$alreadyFired && ($dueMark === null || $mark->greaterThan($dueMark))) {
                    $dueMark = $mark;
                }
            }

            if ($dueMark === null && !$this->option('force')) {
                continue;
            }

            // Catch-up guard: a mark that came due long ago was already covered by
            // the app's offline capture during the gap. Skip it so a reconnecting
            // guard isn't hit with a retroactive online request (finding #3).
            if ($dueMark !== null && !$this->option('force') && $dueMark->lessThan($staleCutoff)) {
                $skippedStale++;
                continue;
            }

            try {
                $photos->createRequest($shift, null, PhotoRequest::TYPE_SCHEDULED);
                $fired++;
            } catch (\Throwable $e) {
                $this->warn("Failed to dispatch photo for shift {$shift->id}: {$e->getMessage()}");
            }
        }

        $this->info("Dispatched {$fired} scheduled photo check(s); skipped {$skippedOffline} offline guard(s), {$skippedStale} stale catch-up mark(s).");

        return self::SUCCESS;
    }

    /**
     * A guard with no recent GPS ping is unreachable by online push. Mirrors
     * WakefulnessService::guardIsOffline so the two capabilities share the one
     * comms-interruption rule (GuardLocation::isCommsInterrupted) and never
     * diverge.
     */
    private function guardIsOffline(string $guardId): bool
    {
        $location = GuardLocation::where('guard_id', $guardId)->first();

        return !$location || $location->isCommsInterrupted();
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
