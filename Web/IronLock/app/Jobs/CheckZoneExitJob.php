<?php

namespace App\Jobs;

use App\Domains\Alerts\Models\Alert;
use App\Domains\Alerts\Services\AlertService;
use App\Domains\Geofences\Services\GeofenceService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Sites\Models\Site;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CheckZoneExitJob — deadline gate for ZONE_EXIT alerts.
 *
 * Dispatched (delayed) when a guard transitions INSIDE→OUTSIDE the geofence.
 * The delay depends on why they are outside (set by GPSTrackingService):
 *  - SHIFT_START positioning — fires AT scheduled_start (immediate if already
 *    past = late start; delayed until then for an early start).
 *  - MID_SHIFT excursion — fires after the site grace period.
 *
 * When it fires it re-confirms before raising a CRITICAL alert: the guard must
 * still be outside, this must still be the SAME excursion it was dispatched for
 * (the live row's zone_left_at anchor still matches — a guard who returned and
 * left again is policed by a newer job, not this stale one), the shift must
 * still be active, and there must be no open ZONE_EXIT alert already.
 *
 * Note: a COMMS_INTERRUPTED gap never reaches here — it isn't a zone exit and
 * doesn't trigger a transition.
 */
class CheckZoneExitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** The guard's first established position of the shift was outside — judged against scheduled_start. */
    const REASON_SHIFT_START = 'SHIFT_START';

    /** The guard was at post and then stepped out — judged against the site grace period. */
    const REASON_MID_SHIFT = 'MID_SHIFT';

    public function __construct(
        private readonly string $guardId,
        private readonly string $shiftId,
        private readonly string $exitedAt, // ISO anchor of the excursion this job polices (guard_locations.zone_left_at)
        private readonly string $reason = self::REASON_MID_SHIFT,
    ) {}

    public function handle(AlertService $alertService): void
    {
        // Guard must still be outside the zone — a return cancels the alert.
        $location = GuardLocation::where('guard_id', $this->guardId)->first();

        if (!$location || $location->zone_status === GeofenceService::STATUS_INSIDE_ZONE) {
            Log::info('CheckZoneExitJob: guard back inside zone, no alert raised', [
                'guard_id' => $this->guardId,
            ]);
            return;
        }

        // Must still be the excursion this job was dispatched for. If the guard
        // returned and left again, the live row carries a newer anchor and a
        // newer job polices it — this stale job must not fire early against the
        // old exit (with the old timestamp). Compared on whole-second precision
        // (the column is a second-resolution TIMESTAMP).
        if (!$location->zone_left_at
            || $location->zone_left_at->timestamp !== Carbon::parse($this->exitedAt)->timestamp) {
            Log::info('CheckZoneExitJob: excursion superseded or ended, no alert raised', [
                'guard_id' => $this->guardId,
                'dispatched_for' => $this->exitedAt,
            ]);
            return;
        }

        // Shift must still be active.
        $shift = Shift::with(['assignedGuard', 'site'])->find($this->shiftId);

        if (!$shift || $shift->status !== Shift::STATUS_ACTIVE) {
            return;
        }

        // Don't double-raise: one open ZONE_EXIT alert per shift is enough.
        $alreadyOpen = Alert::where('shift_id', $this->shiftId)
            ->where('type', 'ZONE_EXIT')
            ->where('status', 'OPEN')
            ->exists();

        if ($alreadyOpen) {
            return;
        }

        Log::info('CheckZoneExitJob: raising ZONE_EXIT alert', [
            'guard_id' => $this->guardId,
            'shift_id' => $this->shiftId,
            'exited_at' => $this->exitedAt,
            'reason' => $this->reason,
        ]);

        $alertService->createZoneExitAlert(
            $this->guardId,
            $this->shiftId,
            $location,
            $shift->assignedGuard,
            $shift->site,
            $this->exitedAt,
            $this->reason
        );

        // Mirror the alert onto the immutable shift timeline so the audit trail
        // shows it, not just the Alert Feed.
        $this->logZoneExitEvent($location, $shift);
    }

    /**
     * Append a ZONE_EXIT row to the shift_events audit trail when the alert is
     * raised. Best-effort — an audit failure must never fail the job (the alert
     * itself is already persisted).
     */
    private function logZoneExitEvent(GuardLocation $location, Shift $shift): void
    {
        try {
            $now = Carbon::now();

            DB::table('shift_events')->insert([
                'id' => (string) Str::uuid(),
                'shift_id' => $this->shiftId,
                'guard_id' => $this->guardId,
                'event_type' => 'ZONE_EXIT',
                'metadata' => json_encode([
                    'reason' => $this->reason,
                    'exited_at' => $this->exitedAt,
                    'scheduled_start' => optional($shift->scheduled_start)->toISOString(),
                    'grace_period_minutes' => $shift->site?->grace_period_minutes ?? Site::DEFAULT_GRACE_PERIOD_MINUTES,
                    'coordinates' => [
                        'latitude' => $location->latitude,
                        'longitude' => $location->longitude,
                    ],
                ]),
                'recorded_at' => $now,
                'server_received_at' => $now,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log zone exit event', [
                'guard_id' => $this->guardId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
