<?php

namespace App\Domains\Geofences\Models;

use App\Domains\Admins\Models\Admin;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Sites\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Geofence Model - MySQL Spatial Polygon Boundaries
 *
 * Represents virtual boundaries around physical sites using native MySQL spatial:
 * - POLYGON geometry with SRID 4326 for GPS coordinates
 * - ST_CONTAINS point-in-polygon validation via spatial index
 * - Version control for boundary updates
 * - Active/inactive status for multiple boundary management
 */
class Geofence extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'geofences';

    /**
     * The primary key for the model.
     */
    protected $primaryKey = 'id';

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'site_id',
        'name',
        'polygon',
        'version',
        'is_active',
        'created_by',
    ];

    /**
     * Attributes hidden from array/JSON serialization.
     *
     * The `polygon` column is raw MySQL spatial binary (WKB) and is NOT valid
     * UTF-8 — serializing it with json_encode() fails ("Malformed UTF-8
     * characters") and breaks any Blade @json() output. The frontend should use
     * the computed `coordinates` array instead (see getPolygonCoordinates()).
     */
    protected $hidden = [
        'polygon',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'is_active' => 'boolean',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Get the site this geofence belongs to.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Get the admin who created this geofence.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Get the shifts assigned to this geofence.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class, 'geofence_id');
    }

    /**
     * Scope for active geofences only.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Check if a point (lat, lng) is inside this geofence using native MySQL ST_CONTAINS.
     * This is the core geofencing validation method.
     *
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public function containsPoint(float $latitude, float $longitude): bool
    {
        // Point-in-polygon validation via spatial ST_CONTAINS.
        //
        // The test point is built with ST_GeomFromText(wkt, 4326) so it carries
        // the same SRID (4326) as the stored polygon — MySQL 8 enforces matching
        // SRIDs and would otherwise raise a "different SRIDs" error. This form is
        // portable across MariaDB 10.4 and MySQL 8 (the 2-arg ST_SRID() variant
        // is not supported by MariaDB). The WKT is assembled in PHP with a
        // locale-independent format; note MySQL expects "longitude latitude".
        $point = sprintf('POINT(%F %F)', $longitude, $latitude);

        $result = DB::selectOne(
            "SELECT ST_CONTAINS(polygon, ST_GeomFromText(?, 4326)) as contains FROM geofences WHERE id = ?",
            [$point, $this->id]
        );

        return (bool) $result->contains;
    }

    /**
     * Create a polygon from coordinate array for database storage.
     * Expects array of [lat, lng] coordinate pairs.
     *
     * @param array $coordinates Array of [lat, lng] pairs
     * @return string WKT (Well-Known Text) polygon string
     */
    public static function createPolygonFromCoordinates(array $coordinates): string
    {
        // Convert to WKT format for MySQL
        // Note: MySQL expects longitude, latitude order in WKT
        $wktCoords = array_map(function ($coord) {
            return $coord[1] . ' ' . $coord[0]; // longitude latitude
        }, $coordinates);

        // Close the polygon if not already closed
        if ($wktCoords[0] !== end($wktCoords)) {
            $wktCoords[] = $wktCoords[0];
        }

        return 'POLYGON((' . implode(',', $wktCoords) . '))';
    }

    /**
     * Set polygon from coordinate array.
     *
     * @param array $coordinates
     */
    public function setPolygonFromCoordinates(array $coordinates): void
    {
        $wkt = self::createPolygonFromCoordinates($coordinates);

        // Use raw SQL to set the spatial column
        DB::statement(
            "UPDATE geofences SET polygon = ST_GeomFromText(?, 4326) WHERE id = ?",
            [$wkt, $this->id]
        );
    }

    /**
     * Get polygon coordinates as array for frontend display.
     *
     * @return array|null Array of [lat, lng] coordinate pairs
     */
    public function getPolygonCoordinates(): ?array
    {
        if (!$this->id) return null;

        $result = DB::selectOne(
            "SELECT ST_AsText(polygon) as polygon_text FROM geofences WHERE id = ?",
            [$this->id]
        );

        if (!$result || !$result->polygon_text) return null;

        // Parse WKT POLYGON string to coordinate array
        // Format: "POLYGON((lng lat, lng lat, ...))"
        preg_match('/POLYGON\(\(([^)]+)\)\)/', $result->polygon_text, $matches);
        if (!$matches) return null;

        $coordPairs = explode(',', $matches[1]);
        $coordinates = [];

        foreach ($coordPairs as $pair) {
            $coords = explode(' ', trim($pair));
            if (count($coords) === 2) {
                $coordinates[] = [
                    (float) $coords[1], // latitude
                    (float) $coords[0], // longitude
                ];
            }
        }

        return $coordinates;
    }
}