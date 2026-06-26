<?php

namespace App\Domains\Authentication\Models;

use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ShiftAccessLink — a one-time SSO sign-in link for a guard's shift.
 *
 * The raw token is never persisted; only its SHA-256 hash (token_hash) is
 * stored, so the row alone can never reconstruct a working link. A link is
 * "live" only while it has not been used, has not been superseded by a newer
 * link (invalidated_at), and has not passed its expires_at. See
 * ShiftAccessLinkService for generation/redemption — redemption reuses the
 * same login-window rules as a normal password login.
 */
class ShiftAccessLink extends Model
{
    use HasUuids;

    protected $table = 'shift_access_links';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'shift_id',
        'guard_id',
        'token_hash',
        'created_by',
        'used_at',
        'invalidated_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
            'invalidated_at' => 'datetime',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * The shift this link signs the guard in to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class, 'shift_id');
    }

    /**
     * The guard authorised to use this link.
     */
    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /**
     * The admin who generated this link.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Whether the link is still usable: not consumed, not superseded, not past
     * its expiry. (The login-window and guard/shift checks are applied on top of
     * this at redemption time — this is only the link's own lifecycle gate.)
     */
    public function isLive(): bool
    {
        return $this->used_at === null
            && $this->invalidated_at === null
            && $this->expires_at !== null
            && Carbon::now()->lessThan($this->expires_at);
    }
}
