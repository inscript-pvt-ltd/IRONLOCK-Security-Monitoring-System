<?php

namespace App\Console\Commands;

use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Photos\Services\PhotoVerificationService;
use App\Domains\Shifts\Models\Shift;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Fire randomised verification photo checks on active shifts (spec §8.2).
 *
 * Unpredictability is the point: a guard must not be able to anticipate when a
 * photo will be demanded. This runs on a short cadence and, for each active
 * shift, fires a SCHEDULED request with a per-run probability — but only if the
 * shift has no outstanding request and its last check was at least
 * MIN_GAP_MINUTES ago, so checks stay spread out rather than clustering.
 */
class DispatchScheduledPhotos extends Command
{
    /** Minimum spacing between automatic checks on the same shift. */
    public const MIN_GAP_MINUTES = 45;

    /** Probability (0–100) that an eligible shift is checked on a given run. */
    public const FIRE_PERCENT = 35;

    protected $signature = 'photos:dispatch-scheduled
                            {--force : Fire on every eligible shift regardless of probability}';

    protected $description = 'Fire randomised verification photo checks on active shifts';

    public function handle(PhotoVerificationService $photos): int
    {
        $now = Carbon::now();
        $gapCutoff = $now->copy()->subMinutes(self::MIN_GAP_MINUTES);

        $shifts = Shift::with('assignedGuard')
            ->where('status', Shift::STATUS_ACTIVE)
            ->get();

        $fired = 0;

        foreach ($shifts as $shift) {
            if (!$shift->assignedGuard) {
                continue;
            }

            // Skip shifts with an outstanding request or a recent check.
            $hasPending = PhotoRequest::where('shift_id', $shift->id)
                ->where('status', PhotoRequest::STATUS_PENDING)
                ->exists();
            if ($hasPending) {
                continue;
            }

            $recent = PhotoRequest::where('shift_id', $shift->id)
                ->where('requested_at', '>=', $gapCutoff)
                ->exists();
            if ($recent) {
                continue;
            }

            // Randomised: only a fraction of eligible shifts fire each run.
            if (!$this->option('force') && random_int(1, 100) > self::FIRE_PERCENT) {
                continue;
            }

            try {
                $photos->createRequest($shift, null, PhotoRequest::TYPE_SCHEDULED);
                $fired++;
            } catch (\Throwable $e) {
                $this->warn("Failed to dispatch photo for shift {$shift->id}: {$e->getMessage()}");
            }
        }

        $this->info("Dispatched {$fired} scheduled photo check(s).");

        return self::SUCCESS;
    }
}
