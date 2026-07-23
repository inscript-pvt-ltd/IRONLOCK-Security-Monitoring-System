<?php

namespace App\Domains\Nonces\Models;

use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Nonce extends Model
{
    use HasUuids;

    // Nonce types (spec §23.6). ONLINE = issued live per request (90s expiry);
    // OFFLINE_POOL = pre-fetched batch for offline capture, valid for the whole
    // shift (NonceService::offlineExpiryFor) so it spans the 50–70-min marks.
    public const TYPE_ONLINE = 'ONLINE';
    public const TYPE_OFFLINE_POOL = 'OFFLINE_POOL';

    /**
     * The `nonces` table has a `created_at` but no `updated_at` column.
     */
    const UPDATED_AT = null;

    /**
     * The table associated with the model.
     */
    protected $table = 'nonces';

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
        'nonce_value',
        'issued_at',
        'expires_at',
        'used_at',
        'type',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Whether the nonce is still usable: never used and not past expiry.
     */
    public function isUsable(): bool
    {
        return is_null($this->used_at) && $this->expires_at->isFuture();
    }

    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }
}