<?php

namespace App\Jobs;

use App\Domains\Alerts\Models\Alert;
use App\Domains\Alerts\Services\AlertService;
use App\Domains\Geofences\Services\GeofenceService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * CheckZoneExitJob — grace-period gate for ZONE_EXIT alerts.
 *
 * Dispatched (delayed by the site grace period) when a guard transitions
 * INSIDE→OUTSIDE the geofence. When it fires it re-confirms the situation
 * before raising a CRITICAL alert: the guard must still be outside, the shift
 * must still be active, and there must be no existing open ZONE_EXIT alert.
 * A guard who returns to the zone, or whose shift ends, produces no alert.
 *
 * Note: a COMMS_INTERRUPTED gap never reaches here — it isn't a zone exit and
 * doesn't trigger a transition.
 */
class CheckZoneExitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly string $guardId,
        private readonly string $shiftId,
        private readonly string $exitedAt, // ISO timestamp the guard first left the zone
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
        ]);

        $alertService->createZoneExitAlert(
            $this->guardId,
            $this->shiftId,
            $location,
            $shift->assignedGuard,
            $shift->site,
            $this->exitedAt
        );
    }
}
