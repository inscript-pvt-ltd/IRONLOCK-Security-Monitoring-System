<?php

namespace App\Domains\Geofences\Services;

use Illuminate\Support\Facades\DB;

/**
 * GeofenceService — native MySQL/MariaDB spatial point-in-polygon check.
 *
 * The single spatial primitive the app relies on: does a coordinate fall inside
 * a stored geofence polygon (SRID 4326, via ST_CONTAINS)?
 *
 * Zone-status resolution, transition detection and the grace-period zone-exit
 * decision all live in GPSTrackingService (the live GPS ingestion path), so
 * there is ONE source of truth for zone logic. Earlier parallel helpers on this
 * class (validate/processZoneTransition/batch/grace-period/stats) were dead
 * duplicates of that path and were removed.
 */
class GeofenceService
{
    /** Zone status vocabulary — shared with GuardLocation.zone_status. */
    const STATUS_INSIDE_ZONE = 'INSIDE_ZONE';
    const STATUS_OUTSIDE_ZONE = 'OUTSIDE_ZONE';

    /**
     * Check if coordinates are inside a specific active geofence using MySQL
     * ST_CONTAINS. Core spatial validation with native database performance.
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
}
