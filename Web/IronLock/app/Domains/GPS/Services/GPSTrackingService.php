<?php

namespace App\Domains\GPS\Services;

use App\Domains\GPS\Models\GuardLocation;

class GPSTrackingService
{
    /**
     * Record a new GPS location for a guard.
     *
     * @param string $guardId
     * @param string $shiftId
     * @param array $locationData
     * @return GuardLocation
     */
    public function recordLocation(string $guardId, string $shiftId, array $locationData): GuardLocation
    {
        // Implementation will be added later
        return new GuardLocation();
    }

    /**
     * Get the current location for a guard.
     *
     * @param string $guardId
     * @return GuardLocation|null
     */
    public function getCurrentLocation(string $guardId): ?GuardLocation
    {
        // Implementation will be added later
        return null;
    }

    /**
     * Check if guard is within a geofence.
     *
     * @param float $latitude
     * @param float $longitude
     * @param string $geofenceId
     * @return bool
     */
    public function isWithinGeofence(float $latitude, float $longitude, string $geofenceId): bool
    {
        // Implementation will be added later
        return false;
    }
}