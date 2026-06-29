<?php

namespace App\Domains\GPS\Models;

use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GuardLocation — Live guard position (one mutable row per guard).
 *
 * Mirrors the `guard_locations` migration: NOT append-only, NO history. Each
 * 15-second GPS ping REPLACES the previous coordinates via UPSERT keyed on
 * `guard_id` (which is the primary key — there is no surrogate `id`). The table
 * has no `created_at`; `updated_at` is the authoritative "last seen" timestamp.
 * It is set explicitly in PHP (UTC) on every ping by GPSTrackingService — NOT
 * left to the column's MySQL ON UPDATE CURRENT_TIMESTAMP default — so the value
 * never depends on the DB session timezone and always refreshes even when a
 * repeat ping carries otherwise-identical coordinates (project tz gotcha).
 */
class GuardLocation extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'guard_locations';

    /**
     * Primary key is guard_id — one live row per guard, not a surrogate id.
     */
    protected $primaryKey = 'guard_id';

    /**
     * The "type" of the primary key ID.
     */
    protected $keyType = 'string';

    /**
     * The key is a guard UUID supplied on write, not auto-incrementing.
     */
    public $incrementing = false;

    /**
     * The table has no `created_at` column — disable it so Eloquent never
     * tries to write one. `updated_at` is kept (managed by the DB).
     */
    const CREATED_AT = null;

    /**
     * Seconds since the last server-side update before a live guard is treated
     * as COMMS_INTERRUPTED (two consecutive missed 15s pings). Single source of
     * truth — used by isCommsInterrupted() and the dashboard KPI so the count
     * and the per-guard status can never diverge.
     *
     * NOTE: the wireframe settings screen (GPS-009) shows a configurable 90s
     * threshold; the Phase 3.3 plan specified 30s. Kept at 30s pending sign-off.
     */
    const COMMS_TIMEOUT_SECONDS = 30;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'guard_id',
        'shift_id',
        'latitude',
        'longitude',
        'accuracy',
        'battery_level',
        'zone_status',
        'recorded_at',
        'updated_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'accuracy' => 'decimal:2',
            'battery_level' => 'integer',
            'recorded_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The guard this live position belongs to.
     *
     * Named `assignedGuard` (not `guard`) to avoid colliding with Eloquent's
     * base Model::guard(array $guarded) mass-assignment method, mirroring
     * Shift::assignedGuard() and ShiftEvent::assignedGuard().
     */
    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /**
     * The shift this position was recorded during.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * COMMS_INTERRUPTED — two consecutive missed 15s pings (> 30s since the
     * last server-side update). This is a comms gap, NOT a zone exit; the
     * dashboard greys the guard out and no zone-exit alert is raised for it.
     */
    public function isCommsInterrupted(): bool
    {
        return !$this->updated_at
            || $this->updated_at->diffInSeconds(now()) > self::COMMS_TIMEOUT_SECONDS;
    }
}
