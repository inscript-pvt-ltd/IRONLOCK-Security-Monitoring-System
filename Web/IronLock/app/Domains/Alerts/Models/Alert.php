<?php

namespace App\Domains\Alerts\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

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
        'alert_type',
        'title',
        'message',
        'priority',
        'latitude',
        'longitude',
        'acknowledged_at',
        'acknowledged_by',
        'resolved_at',
        'resolved_by',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'acknowledged_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}