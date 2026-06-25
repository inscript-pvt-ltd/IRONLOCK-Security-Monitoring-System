<?php

namespace App\Domains\Photos\Models;

use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use App\Domains\Nonces\Models\Nonce;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * PhotoRequest — a single photo-verification request raised against a shift.
 *
 * Mirrors the canonical `photo_requests` schema. Created either by an admin
 * ("manual") or the scheduler ("scheduled"); each request issues one ONLINE
 * nonce and is fulfilled by exactly one PhotoEvidence row.
 */
class PhotoRequest extends Model
{
    use HasUuids;

    // request_type values.
    public const TYPE_MANUAL = 'manual';
    public const TYPE_SCHEDULED = 'scheduled';

    // status values (spec §23.6).
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_FULFILLED = 'FULFILLED';
    public const STATUS_TIMEOUT = 'TIMEOUT';
    public const STATUS_ANOMALY = 'ANOMALY';

    /**
     * The table associated with the model.
     */
    protected $table = 'photo_requests';

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
     * The attributes that are mass assignable — mirrors the real columns.
     */
    protected $fillable = [
        'shift_id',
        'guard_id',
        'nonce_id',
        'requested_by',
        'request_type',
        'nonce_issued_at',
        'status',
        'requested_at',
        'submitted_at',
        'server_received_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'nonce_issued_at' => 'datetime',
            'requested_at' => 'datetime',
            'submitted_at' => 'datetime',
            'server_received_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function nonce(): BelongsTo
    {
        return $this->belongsTo(Nonce::class, 'nonce_id');
    }

    public function evidence(): HasOne
    {
        return $this->hasOne(PhotoEvidence::class, 'photo_request_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    public function requestedByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }
}
