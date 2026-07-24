<?php

namespace App\Domains\Sites\Models;

use App\Domains\Admins\Models\Admin;
use App\Domains\Geofences\Models\Geofence;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Site Model - Physical locations with GPS coordinates and geofencing
 *
 * Represents security sites where guards are deployed with:
 * - GPS coordinates for mapping and location validation
 * - Grace periods for zone exit tolerance
 * - Contact information for site management
 * - Geofence polygon boundaries for guard monitoring
 */
class Site extends Model
{
    use HasUuids;

    /**
     * Fallback zone-exit grace period (minutes) when a site has none set. Single
     * source of truth for the `?? 5` fallbacks scattered across the zone-exit
     * path (GPSTrackingService, CheckZoneExitJob, AlertService) and the create/
     * update defaults — matches the `grace_period_minutes` DB column default.
     */
    const DEFAULT_GRACE_PERIOD_MINUTES = 5;

    /**
     * The table associated with the model.
     */
    protected $table = 'sites';

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
        'name',
        'address',
        'latitude',
        'longitude',
        'grace_period_minutes',
        'status',
        'contact_person',
        'contact_phone',
        'instructions',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'grace_period_minutes' => 'integer',
            'archived_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Site status constants
     */
    const STATUS_ACTIVE = 'active';
    const STATUS_INACTIVE = 'inactive';

    /**
     * Get the admin who created this site.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Get all geofences for this site.
     */
    public function geofences(): HasMany
    {
        return $this->hasMany(Geofence::class);
    }

    /**
     * Get the active geofence for this site.
     */
    public function activeGeofence()
    {
        return $this->geofences()->where('is_active', true)->first();
    }

    /**
     * Get all shifts scheduled for this site.
     */
    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /**
     * Scope for active sites only.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Exclude archived (soft-removed) sites. Archived sites are hidden from the
     * admin list and the shift picker, but the row is kept so historical shifts
     * still resolve the real site — so this scope is applied only to listing and
     * picker queries, never as a global scope (@see the archived_at migration).
     */
    public function scopeNotArchived($query)
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Whether this site has been archived (soft-removed from the roster).
     */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Check if site has GPS coordinates.
     */
    public function hasCoordinates(): bool
    {
        return !is_null($this->latitude) && !is_null($this->longitude);
    }

    /**
     * Get formatted coordinates for display.
     */
    public function getFormattedCoordinatesAttribute(): string
    {
        if (!$this->hasCoordinates()) {
            return 'No coordinates set';
        }

        return sprintf('%.6f, %.6f', $this->latitude, $this->longitude);
    }
}