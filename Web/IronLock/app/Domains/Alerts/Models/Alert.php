<?php

namespace App\Domains\Alerts\Models;

use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alert — supervisor-actionable event (zone exit, unresponsive guard, etc.).
 *
 * Field names mirror the `alerts` migration: `type` / `severity` /
 * `description` / `raised_at` (NOT alert_type / priority / message). There are
 * no latitude/longitude columns — last-known coordinates live on the related
 * GuardLocation and are baked into the description when an alert is raised.
 */
class Alert extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'alerts';

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
        'guard_id',
        'shift_id',
        'type',        // ZONE_EXIT | GUARD_UNRESPONSIVE | PHOTO_TIMEOUT | ...
        'severity',    // CRITICAL | WARNING
        'status',      // OPEN | ACKNOWLEDGED
        'title',
        'description',
        'raised_at',
        'acknowledged_by',
        'acknowledged_at',
        'acknowledgment_note',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'raised_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The guard this alert was raised for.
     *
     * Named `assignedGuard` (not `guard`) to avoid colliding with Eloquent's
     * base Model::guard(array $guarded) mass-assignment method, mirroring
     * Shift::assignedGuard().
     */
    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /**
     * The shift this alert was raised during.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
}
