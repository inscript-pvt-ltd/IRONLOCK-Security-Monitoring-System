<?php

namespace App\Domains\Geofences\Services;

use App\Domains\Geofences\Models\Geofence;
use App\Domains\Sites\Models\Site;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

/**
 * GeofenceService - Native MySQL Spatial Validation
 *
 * Core geofencing service using MySQL ST_CONTAINS for point-in-polygon validation.
 * Provides zone validation, transition detection, and alert generation for Phase 3.
 *
 * Key Features:
 * - Native MySQL spatial queries with SRID 4326
 * - Zone transition detection (INSIDE_ZONE ↔ OUTSIDE_ZONE)
 * - Grace period handling with configurable timeouts
 * - Batch validation for multiple guard locations
 * - Integration with shift management and alert system
 */
class GeofenceService
{
    /**
     * Zone status constants
     */
    const STATUS_INSIDE_ZONE = 'INSIDE_ZONE';
    const STATUS_OUTSIDE_ZONE = 'OUTSIDE_ZONE';

    /**
     * Check if coordinates are inside a specific geofence using MySQL ST_CONTAINS.
     * Core spatial validation method with native database performance.
     *
     * @param string $geofenceId
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public function isInsideZone(string $geofenceId, float $latitude, float $longitude): bool
    {
        // Build the test point as SRID-4326 WKT so it matches the stored
        // polygon's SRID. ST_GeomFromText(wkt, 4326) is portable across
        // MariaDB 10.4 and MySQL 8 (MariaDB has no 2-arg ST_SRID()).
        // NOTE: MySQL expects "longitude latitude" ordering.
        $point = sprintf('POINT(%F %F)', $longitude, $latitude);

        $row = DB::selectOne(
            'SELECT ST_CONTAINS(polygon, ST_GeomFromText(?, 4326)) AS is_inside
               FROM geofences
              WHERE id = ? AND is_active = 1',
            [$point, $geofenceId]
        );

        return (bool) ($row->is_inside ?? false);
    }

    /**
     * Validate guard location against their assigned site's active geofence.
     * Returns zone status with site context.
     *
     * @param string $guardId
     * @param float $latitude
     * @param float $longitude
     * @return array
     */
    public function validateGuardLocation(string $guardId, float $latitude, float $longitude): array
    {
        // Get guard's current active shift and site
        $shift = $this->getActiveShiftForGuard($guardId);

        if (!$shift) {
            return [
                'status' => 'NO_ACTIVE_SHIFT',
                'zone_status' => null,
                'geofence_id' => null,
                'site_id' => null,
                'message' => 'Guard has no active shift'
            ];
        }

        // Get active geofence for the shift's site
        $geofence = Geofence::where('site_id', $shift->site_id)
            ->where('is_active', true)
            ->first();

        if (!$geofence) {
            return [
                'status' => 'NO_ACTIVE_GEOFENCE',
                'zone_status' => null,
                'geofence_id' => null,
                'site_id' => $shift->site_id,
                'message' => 'No active geofence found for site'
            ];
        }

        // Perform spatial validation
        $isInside = $this->isInsideZone($geofence->id, $latitude, $longitude);
        $zoneStatus = $isInside ? self::STATUS_INSIDE_ZONE : self::STATUS_OUTSIDE_ZONE;

        return [
            'status' => 'VALIDATED',
            'zone_status' => $zoneStatus,
            'geofence_id' => $geofence->id,
            'site_id' => $shift->site_id,
            'shift_id' => $shift->id,
            'is_inside' => $isInside,
            'grace_period_minutes' => $shift->site->grace_period_minutes ?? 5,
            'coordinates' => [
                'latitude' => $latitude,
                'longitude' => $longitude
            ]
        ];
    }

    /**
     * Process zone transition for guard location update.
     * Handles INSIDE_ZONE ↔ OUTSIDE_ZONE transitions with grace period logic.
     *
     * @param string $guardId
     * @param float $latitude
     * @param float $longitude
     * @param string|null $previousZoneStatus
     * @return array
     */
    public function processZoneTransition(string $guardId, float $latitude, float $longitude, ?string $previousZoneStatus = null): array
    {
        $validation = $this->validateGuardLocation($guardId, $latitude, $longitude);

        if ($validation['status'] !== 'VALIDATED') {
            return $validation;
        }

        $currentStatus = $validation['zone_status'];
        $zoneTransition = null;
        $requiresAlert = false;

        // Detect zone transition
        if ($previousZoneStatus && $previousZoneStatus !== $currentStatus) {
            $zoneTransition = "{$previousZoneStatus} → {$currentStatus}";

            // Log zone transition event
            $this->logZoneTransitionEvent(
                $guardId,
                $validation['shift_id'],
                $validation['geofence_id'],
                $previousZoneStatus,
                $currentStatus,
                $latitude,
                $longitude
            );

            // Check if this requires an alert (INSIDE → OUTSIDE)
            if ($previousZoneStatus === self::STATUS_INSIDE_ZONE && $currentStatus === self::STATUS_OUTSIDE_ZONE) {
                $requiresAlert = true;
            }
        }

        return array_merge($validation, [
            'previous_zone_status' => $previousZoneStatus,
            'zone_transition' => $zoneTransition,
            'requires_alert' => $requiresAlert,
            'timestamp' => Carbon::now()->toISOString()
        ]);
    }

    /**
     * Batch validate multiple guard locations for dashboard updates.
     * Optimized for real-time dashboard with minimal database queries.
     *
     * @param array $guardLocations Array of ['guard_id' => string, 'latitude' => float, 'longitude' => float]
     * @return array
     */
    public function batchValidateGuardLocations(array $guardLocations): array
    {
        $results = [];

        foreach ($guardLocations as $location) {
            $guardId = $location['guard_id'];
            $lat = $location['latitude'];
            $lng = $location['longitude'];

            $results[$guardId] = $this->validateGuardLocation($guardId, $lat, $lng);
        }

        return $results;
    }

    /**
     * Check if guard has been outside zone beyond grace period.
     * Used for generating zone exit alerts.
     *
     * @param string $guardId
     * @param Carbon $exitTime
     * @return bool
     */
    public function isOutsideGracePeriod(string $guardId, Carbon $exitTime): bool
    {
        $shift = $this->getActiveShiftForGuard($guardId);

        if (!$shift || !$shift->site) {
            return false;
        }

        $gracePeriodMinutes = $shift->site->grace_period_minutes ?? 5;
        $gracePeriodExpiry = $exitTime->addMinutes($gracePeriodMinutes);

        return Carbon::now()->greaterThan($gracePeriodExpiry);
    }

    /**
     * Test a point against a specific geofence (for geofence setup/testing).
     *
     * @param string $geofenceId
     * @param float $latitude
     * @param float $longitude
     * @return array
     */
    public function testPointInGeofence(string $geofenceId, float $latitude, float $longitude): array
    {
        try {
            $geofence = Geofence::with('site')->findOrFail($geofenceId);
            $isInside = $this->isInsideZone($geofenceId, $latitude, $longitude);

            return [
                'success' => true,
                'is_inside' => $isInside,
                'geofence' => [
                    'id' => $geofence->id,
                    'name' => $geofence->name,
                    'site_name' => $geofence->site->name ?? null
                ],
                'test_point' => [
                    'latitude' => $latitude,
                    'longitude' => $longitude
                ],
                'message' => $isInside ? 'Point is INSIDE the geofence' : 'Point is OUTSIDE the geofence'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => 'Error testing point: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Get active shift for a guard.
     *
     * @param string $guardId
     * @return Shift|null
     */
    private function getActiveShiftForGuard(string $guardId): ?Shift
    {
        return Shift::where('guard_id', $guardId)
            ->where('status', 'active')
            ->with('site')
            ->first();
    }

    /**
     * Log zone transition event to shift_events table.
     *
     * @param string $guardId
     * @param string $shiftId
     * @param string $geofenceId
     * @param string $fromStatus
     * @param string $toStatus
     * @param float $latitude
     * @param float $longitude
     */
    private function logZoneTransitionEvent(string $guardId, string $shiftId, string $geofenceId, string $fromStatus, string $toStatus, float $latitude, float $longitude): void
    {
        try {
            DB::table('shift_events')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'shift_id' => $shiftId,
                'guard_id' => $guardId,
                'event_type' => 'ZONE_TRANSITION',
                'metadata' => json_encode([
                    'geofence_id' => $geofenceId,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'coordinates' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude
                    ]
                ]),
                'recorded_at' => Carbon::now(),
                'server_received_at' => Carbon::now(),
                'created_at' => Carbon::now()
            ]);

            Log::info('Zone transition logged', [
                'guard_id' => $guardId,
                'shift_id' => $shiftId,
                'transition' => "{$fromStatus} → {$toStatus}",
                'coordinates' => [$latitude, $longitude]
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to log zone transition event', [
                'guard_id' => $guardId,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get geofence validation statistics for a site.
     *
     * @param string $siteId
     * @param Carbon $fromDate
     * @param Carbon $toDate
     * @return array
     */
    public function getGeofenceValidationStats(string $siteId, Carbon $fromDate, Carbon $toDate): array
    {
        $stats = DB::selectOne('
            SELECT
                COUNT(*) as total_validations,
                COUNT(CASE WHEN event_type = "ZONE_TRANSITION"
                    AND JSON_EXTRACT(metadata, "$.to_status") = ? THEN 1 END) as zone_exits,
                COUNT(CASE WHEN event_type = "ZONE_TRANSITION"
                    AND JSON_EXTRACT(metadata, "$.to_status") = ? THEN 1 END) as zone_entries
            FROM shift_events se
            JOIN shifts s ON se.shift_id = s.id
            WHERE s.site_id = ?
            AND se.server_received_at BETWEEN ? AND ?
        ', [self::STATUS_OUTSIDE_ZONE, self::STATUS_INSIDE_ZONE, $siteId, $fromDate, $toDate]);

        return [
            'site_id' => $siteId,
            'period' => [
                'from' => $fromDate->toISOString(),
                'to' => $toDate->toISOString()
            ],
            'total_validations' => $stats->total_validations ?? 0,
            'zone_exits' => $stats->zone_exits ?? 0,
            'zone_entries' => $stats->zone_entries ?? 0
        ];
    }
}