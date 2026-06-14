<?php

namespace App\Domains\Photos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class PhotoEvidence extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'photo_evidence';

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
        'photo_request_id',
        'guard_id',
        'shift_id',
        'file_path',
        'original_filename',
        'file_size',
        'mime_type',
        'latitude',
        'longitude',
        'photo_timestamp',
        'verification_status',
        'notes',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'file_size' => 'integer',
            'photo_timestamp' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}