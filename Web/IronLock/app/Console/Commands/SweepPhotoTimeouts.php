<?php

namespace App\Console\Commands;

use App\Domains\Alerts\Services\AlertService;
use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Shifts\Models\ShiftEvent;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Sweep photo requests the guard never answered.
 *
 * A live photo request must be answered within the online response window
 * (config ironlock.photo_response_seconds, default 90s — the SAME value that
 * sets the online nonce TTL, so the upload deadline and the timeout never
 * disagree). A request still PENDING past that window is timed out: its status
 * becomes TIMEOUT, a PHOTO_TIMEOUT audit event is written, and a CRITICAL alert
 * is raised so a supervisor sees an unresponsive guard immediately. Runs every
 * minute.
 */
class SweepPhotoTimeouts extends Command
{
    protected $signature = 'photos:timeout-sweep';

    protected $description = 'Time out photo requests the guard did not answer within the response window and raise a CRITICAL alert';

    public function handle(AlertService $alerts): int
    {
        $now = Carbon::now();
        $window = (int) config('ironlock.photo_response_seconds', 90);
        $cutoff = $now->copy()->subSeconds($window);

        $requests = PhotoRequest::with('shift.assignedGuard')
            ->where('status', PhotoRequest::STATUS_PENDING)
            ->where('requested_at', '<', $cutoff)
            ->get();

        $timedOut = 0;

        foreach ($requests as $request) {
            // Atomic transition guards against a late upload racing the sweep:
            // only the row still PENDING is flipped to TIMEOUT.
            $affected = PhotoRequest::where('id', $request->id)
                ->where('status', PhotoRequest::STATUS_PENDING)
                ->update([
                    'status' => PhotoRequest::STATUS_TIMEOUT,
                    'server_received_at' => $now,
                ]);

            if ($affected !== 1) {
                continue;
            }

            $timedOut++;

            try {
                ShiftEvent::create([
                    'id' => (string) Str::uuid(),
                    'shift_id' => $request->shift_id,
                    'guard_id' => $request->guard_id,
                    'event_type' => 'PHOTO_TIMEOUT',
                    'metadata' => [
                        'request_id' => $request->id,
                        'requested_at' => optional($request->requested_at)->toISOString(),
                        'window_seconds' => $window,
                    ],
                    'recorded_at' => $now,
                    'server_received_at' => $now,
                ]);
            } catch (\Throwable $e) {
                // Audit is non-critical; the status transition already holds.
            }

            try {
                $guard = $request->shift?->assignedGuard;
                $name = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Guard';
                $alerts->createAlert($request->guard_id, $request->shift_id, [
                    'type' => 'PHOTO_TIMEOUT',
                    'severity' => 'CRITICAL',
                    'title' => "Photo Timeout — {$name}",
                    'description' => "{$name} did not respond to a verification photo request within "
                        . $window . " seconds.",
                ]);
            } catch (\Throwable $e) {
                // Alerting is best-effort; the timeout is already recorded.
            }
        }

        $this->info("Timed out {$timedOut} photo request(s).");

        return self::SUCCESS;
    }
}
