<?php

namespace App\Domains\Backups\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Backup — one monthly evidence archive record.
 *
 * Tracks ONLY the generated ZIP archive for a calendar month; it never owns or
 * deletes the original immutable photo_evidences. A completed month is
 * downloadable for config('ironlock.backup_retention_days') days after it ends
 * (expires_at), after which the scheduled cleanup deletes this row and any
 * cached ZIP (zip_path). See BackupService.
 */
class Backup extends Model
{
    use HasUuids;

    protected $table = 'backups';
    protected $primaryKey = 'id';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'month_key',
        'label',
        'period_start',
        'period_end',
        'expires_at',
        'zip_path',
        'zip_generated_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'expires_at' => 'datetime',
            'zip_generated_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Whether this month's retention window has elapsed (cleanup target).
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && Carbon::now()->greaterThanOrEqualTo($this->expires_at);
    }
}
