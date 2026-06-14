<?php

namespace App\Domains\Wakefulness\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class WakefulnessCheck extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'wakefulness_checks';

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
        'question_text',
        'expected_response',
        'actual_response',
        'response_time_seconds',
        'is_correct',
        'sent_at',
        'responded_at',
        'status',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'is_correct' => 'boolean',
            'response_time_seconds' => 'integer',
            'sent_at' => 'datetime',
            'responded_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}