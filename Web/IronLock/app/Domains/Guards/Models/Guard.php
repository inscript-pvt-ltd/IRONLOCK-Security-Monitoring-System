<?php

namespace App\Domains\Guards\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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
        'hire_date',
        'employment_status',
        'status',
        'created_by',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'active_session_token_id',
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
}