<?php

namespace App\Domains\Shifts\Models;

use App\Domains\Guards\Models\Guard;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ShiftEvent Model - Immutable Shift Audit Trail
 *
 * Append-only record of events that occur during a shift (GPS pings,
 * wakefulness checks, photo requests, etc.). Mirrors the `shift_events`
 * migration:
 * - Device timestamp (`recorded_at`) kept for audit only
 * - Authoritative server timestamp (`server_received_at`)
 * - Free-form JSON `metadata` payload per event type
 *
 * The table has a `created_at` column but no `updated_at` — records are
 * never modified after insertion — so automatic `updated_at` handling is
 * disabled via `UPDATED_AT = null`.
 */
class ShiftEvent extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'shift_events';

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
     * The table is append-only and has no `updated_at` column.
     */
    const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shift_id',
        'guard_id',
        'event_type',
        'metadata',
        'recorded_at',
        'server_received_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'recorded_at' => 'datetime',
            'server_received_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the shift this event belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the guard this event was recorded for.
     *
     * Named `assignedGuard` (not `guard`) to avoid colliding with Eloquent's
     * base Model::guard() mass-assignment method, mirroring Shift::assignedGuard().
     */
    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }
}
