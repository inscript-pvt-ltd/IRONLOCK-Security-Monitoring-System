<?php

namespace App\Console\Commands;

use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sweep shifts whose check-in / start window has expired and mark them Missed.
 *
 * A shift is Missed when it is still Scheduled or Checked-In, its
 * scheduled_start + window has passed, it never became Active, and no
 * supervisor late-check-in override is currently open. Runs every minute so a
 * guard who fails to check in or start is flagged promptly, surfacing the
 * "contact your supervisor" path on both the app and the dashboard.
 */
class MarkMissedShifts extends Command
{
    protected $signature = 'shifts:mark-missed';

    protected $description = 'Mark scheduled/checked-in shifts whose check-in window has expired as missed';

    public function handle(): int
    {
        $window = Shift::windowMinutes();
        $now = Carbon::now();
        $cutoff = $now->copy()->subMinutes($window);

        $shifts = Shift::whereIn('status', [Shift::STATUS_SCHEDULED, Shift::STATUS_CHECKED_IN])
            ->where('scheduled_start', '<', $cutoff)
            ->where(function ($q) use ($now) {
                $q->whereNull('checkin_override_until')
                  ->orWhere('checkin_override_until', '<', $now);
            })
            ->get();

        $marked = 0;

        foreach ($shifts as $shift) {
            // Re-check via the model so the override/window logic stays in one
            // place and a row that slipped past the SQL filter is still safe.
            if (!$shift->windowHasExpired()) {
                continue;
            }

            $previous = $shift->status;

            if ($shift->markMissed()) {
                $marked++;

                try {
                    ShiftEvent::create([
                        'id' => (string) Str::uuid(),
                        'shift_id' => $shift->id,
                        'guard_id' => $shift->guard_id,
                        'event_type' => 'SHIFT_MISSED',
                        'metadata' => [
                            'previous_status' => $previous,
                            'scheduled_start' => optional($shift->scheduled_start)->toISOString(),
                            'window_minutes' => $window,
                        ],
                        'recorded_at' => $now,
                        'server_received_at' => $now,
                    ]);
                } catch (\Throwable $e) {
                    // Audit is non-critical; the status transition already holds.
                }
            }
        }

        $this->info("Marked {$marked} shift(s) as missed.");

        return self::SUCCESS;
    }
}
