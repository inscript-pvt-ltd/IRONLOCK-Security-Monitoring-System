<?php

namespace App\Domains\GPS\Services;

use App\Domains\Alerts\Services\AlertService;
use App\Domains\Geofences\Services\GeofenceService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Shifts\Models\Shift;
use App\Events\GuardLocationUpdated;
use App\Jobs\CheckZoneExitJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GPSTrackingService — ingest live guard positions and drive zone logic.
 *
 * One mutable row per guard (UPSERT, no history). Each ping runs a server-side
 * ST_CONTAINS check via GeofenceService to set zone_status. On an INSIDE→OUTSIDE
 * transition we log a ZONE_TRANSITION shift_event and schedule a grace-period
 * CheckZoneExitJob; the job — not this method — decides whether to raise a
 * ZONE_EXIT alert. The client clock is never trusted: `recorded_at` is kept for
 * diagnostics only and `updated_at` (the DB's authoritative "last seen") is what
 * the dashboard and COMMS_INTERRUPTED logic rely on.
 */
class GPSTrackingService
{
    public function __construct(
        private readonly GeofenceService $geofenceService,
        private readonly AlertService $alertService,
    ) {}

    /**
     * Record one GPS ping for a guard, updating their live location row and
     * triggering zone-transition handling. Returns the persisted GuardLocation.
     */
    public function recordLocation(string $guardId, string $shiftId, array $locationData): GuardLocation
    {
        $now = Carbon::now();

        // Read the prior zone status before the UPSERT overwrites the row, so
        // we can detect a transition.
        $previous = GuardLocation::where('guard_id', $guardId)->first();
        $previousZoneStatus = $previous?->zone_status;

        // Resolve zone status via the shift's geofence (server-side spatial check).
        $shift = Shift::with('site')->find($shiftId);
        $geofenceId = $shift?->geofence_id;
        $zoneStatus = GeofenceService::STATUS_INSIDE_ZONE;

        if ($geofenceId) {
            $isInside = $this->geofenceService->isInsideZone(
                $geofenceId,
                (float) $locationData['latitude'],
                (float) $locationData['longitude']
            );

            $zoneStatus = $isInside
                ? GeofenceService::STATUS_INSIDE_ZONE
                : GeofenceService::STATUS_OUTSIDE_ZONE;
        }

        // UPSERT: replace the single live-location row for this guard.
        // `updated_at` is the authoritative "last seen" time that drives
        // GuardLocation::isCommsInterrupted(). It is set explicitly in PHP (UTC)
        // rather than left to the column's ON UPDATE CURRENT_TIMESTAMP default,
        // because the DB session timezone is not guaranteed to be UTC — relying
        // on the DB clock would drift the 30s comms threshold (project tz gotcha).
        $location = GuardLocation::updateOrCreate(
            ['guard_id' => $guardId],
            [
                'shift_id' => $shiftId,
                'latitude' => $locationData['latitude'],
                'longitude' => $locationData['longitude'],
                'accuracy' => $locationData['accuracy'] ?? null,
                'battery_level' => $locationData['battery_level'] ?? null,
                'zone_status' => $zoneStatus,
                'recorded_at' => isset($locationData['recorded_at'])
                    ? Carbon::parse($locationData['recorded_at'])
                    : null,
                'updated_at' => Carbon::now(),
            ]
        );

        // Only act when the zone status actually changes (avoids per-ping noise).
        if ($previousZoneStatus !== null && $previousZoneStatus !== $zoneStatus) {
            $this->logZoneTransitionEvent(
                $guardId,
                $shiftId,
                $geofenceId,
                $previousZoneStatus,
                $zoneStatus,
                (float) $locationData['latitude'],
                (float) $locationData['longitude'],
                $now
            );

            // On leaving the zone, schedule a grace-period check. The job
            // re-confirms the guard is still outside before raising an alert.
            if ($zoneStatus === GeofenceService::STATUS_OUTSIDE_ZONE) {
                $gracePeriodMinutes = $shift?->site?->grace_period_minutes ?? 5;

                CheckZoneExitJob::dispatch($guardId, $shiftId, $now->toISOString())
                    ->delay(now()->addMinutes($gracePeriodMinutes));
            }
        }

        // Push to the dashboard. Soft-fail until broadcasting is configured so a
        // missing driver never blocks the ping.
        try {
            event(new GuardLocationUpdated($guardId, $shiftId, $location));
        } catch (\Throwable $e) {
            Log::debug('GuardLocationUpdated broadcast skipped', ['error' => $e->getMessage()]);
        }

        return $location;
    }

    /**
     * The guard's current live position, or null if none recorded yet.
     */
    public function getCurrentLocation(string $guardId): ?GuardLocation
    {
        return GuardLocation::where('guard_id', $guardId)->first();
    }

    /**
     * Point-in-polygon check delegated to the geofence service.
     */
    public function isWithinGeofence(float $latitude, float $longitude, string $geofenceId): bool
    {
        return $this->geofenceService->isInsideZone($geofenceId, $latitude, $longitude);
    }

    /**
     * Append a ZONE_TRANSITION row to the immutable shift_events audit trail.
     * Best-effort — a logging failure must never block the ping.
     */
    private function logZoneTransitionEvent(
        string $guardId,
        string $shiftId,
        ?string $geofenceId,
        string $fromStatus,
        string $toStatus,
        float $latitude,
        float $longitude,
        Carbon $now
    ): void {
        try {
            DB::table('shift_events')->insert([
                'id' => (string) Str::uuid(),
                'shift_id' => $shiftId,
                'guard_id' => $guardId,
                'event_type' => 'ZONE_TRANSITION',
                'metadata' => json_encode([
                    'geofence_id' => $geofenceId,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'coordinates' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                ]),
                'recorded_at' => $now,
                'server_received_at' => $now,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log zone transition', [
                'guard_id' => $guardId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
