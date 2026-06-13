<?php

namespace App\Domains\Geofences\Services;

use Illuminate\Support\Facades\DB;

class GeofenceService
{
    /**
     * Returns true if (lat,lng) lies inside the active geofence.
     * Uses native MySQL spatial — no client-side math.
     */
    public function isInsideZone(string $geofenceId, float $latitude, float $longitude): bool
    {
        $row = DB::selectOne(
            'SELECT ST_CONTAINS(polygon, ST_SRID(POINT(?, ?), 4326)) AS is_inside
               FROM geofences
              WHERE id = ? AND is_active = 1',
            [$longitude, $latitude, $geofenceId]   // NOTE: POINT(lng, lat)
        );

        return (bool) ($row->is_inside ?? false);
    }

    public function validateGuardLocation(string $geofenceId, float $lat, float $lng): bool
    {
        return $this->isInsideZone($geofenceId, $lat, $lng);
    }
}