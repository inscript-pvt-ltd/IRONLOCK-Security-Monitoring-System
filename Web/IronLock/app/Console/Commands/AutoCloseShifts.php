<?php

namespace App\Console\Commands;

use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Force-close overdue shifts the guard never ended (BACKEND_SHIFT_END_SPEC §2).
 *
 * A shift only closes today when the app POSTs /end. If a guard forgets, or
 * their phone is dead/offline/backgrounded, the shift stays Active forever with
 * no actual_end. This is the server-side guarantee that every shift closes.
 *
 * Any Active shift past scheduled_end + grace is closed with end_type = auto and
 * auto_closed = true (so it surfaces in supervisor reporting), crediting time
 * only up to the contracted scheduled_end. Runs frequently so closures are
 * timely; the grace period lets a slightly-late manual end happen first.
 */
class AutoCloseShifts extends Command
{
    protected $signature = 'shifts:auto-close';

    protected $description = 'Force-close active shifts left open past their scheduled end + grace period';

    public function handle(): int
    {
        $grace = (int) config('ironlock.auto_close_grace_minutes', 45);
        $now = Carbon::now();
        $cutoff = $now->copy()->subMinutes($grace);

        $shifts = Shift::where('status', Shift::STATUS_ACTIVE)
            ->whereNotNull('scheduled_end')
            ->where('scheduled_end', '<', $cutoff)
            ->get();

        $closed = 0;

        foreach ($shifts as $shift) {
            if (!$shift->autoClose()) {
                continue;
            }

            $closed++;

            try {
                ShiftEvent::create([
                    'id' => (string) Str::uuid(),
                    'shift_id' => $shift->id,
                    'guard_id' => $shift->guard_id,
                    'event_type' => 'SHIFT_AUTO_CLOSED',
                    'metadata' => [
                        'grace_minutes' => $grace,
                        'scheduled_end' => optional($shift->scheduled_end)->toISOString(),
                        'actual_end' => optional($shift->actual_end)->toISOString(),
                        'closed_at' => $now->toISOString(),
                    ],
                    'recorded_at' => $now,
                    'server_received_at' => $now,
                ]);
            } catch (\Throwable $e) {
                // Audit is non-critical; the close already holds.
            }
        }

        $this->info("Auto-closed {$closed} overdue shift(s).");

        return self::SUCCESS;
    }
}
