<?php

namespace App\Domains\Guards\Models;

use App\Domains\Admins\Models\Admin;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class Guard extends Authenticatable
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'guards';

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
        'employee_code',
        'first_name',
        'last_name',
        'username',
        'email',
        'password',
        'phone',
        'sia_licence_number',
        'sia_licence_expiry',
        'sia_licence_type',
        'device_identifier',
        'device_name',
        'hmac_secret',
        'push_token',
        'push_token_platform',
        'hire_date',
        'employment_status',
        'erased_at',
        'status',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * hmac_secret is a shared symmetric key — it must never leak through any
     * serialized payload (it is delivered to the app exactly once, explicitly,
     * in the login response).
     */
    protected $hidden = [
        'password',
        'active_session_token_id',
        'hmac_secret',
        'push_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'sia_licence_expiry' => 'date',
            'hire_date' => 'date',
            'account_locked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'erased_at' => 'datetime',
            'password' => 'hashed',
            'failed_login_count' => 'integer',
        ];
    }

    /**
     * Check if the guard's account is locked.
     */
    public function isLocked(): bool
    {
        return !is_null($this->account_locked_at);
    }

    /**
     * Whether this guard's PII has been erased under GDPR right-to-erasure
     * (identity redacted; audit trail preserved). @see GuardErasureService.
     */
    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    /**
     * Exclude GDPR-erased guards. Erased records are anonymised tombstones kept
     * only to keep historical audit rows FK-valid — they must never appear in
     * scheduling, rosters or SIA/expiry checks.
     */
    public function scopeNotErased($query)
    {
        return $query->whereNull('erased_at');
    }

    /**
     * Check if the guard's SIA licence is expired or expiring soon.
     */
    public function isSiaLicenceExpired(): bool
    {
        return $this->sia_licence_expiry < now();
    }

    /**
     * Check if the guard's SIA licence is expiring within the given days.
     */
    public function isSiaLicenceExpiringSoon(int $days = 30): bool
    {
        return $this->sia_licence_expiry <= now()->addDays($days);
    }

    /**
     * Get the shifts for the guard.
     */
    public function shifts()
    {
        return $this->hasMany(\App\Domains\Shifts\Models\Shift::class, 'guard_id', 'id');
    }

    /**
     * The guard's currently-active shift, if any (status = active). A guard can
     * only be on one active shift at a time (one active session per guard), so
     * this is a hasOne; `latest('actual_start')` just makes the pick
     * deterministic if two ever overlap. Read-only convenience for the roster —
     * used to surface the guard's live site on the Guards list; no bearing on
     * shift lifecycle logic.
     */
    public function activeShift()
    {
        return $this->hasOne(\App\Domains\Shifts\Models\Shift::class, 'guard_id', 'id')
            ->where('status', \App\Domains\Shifts\Models\Shift::STATUS_ACTIVE)
            ->latest('actual_start');
    }

    /**
     * Get the admin who created this guard.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}