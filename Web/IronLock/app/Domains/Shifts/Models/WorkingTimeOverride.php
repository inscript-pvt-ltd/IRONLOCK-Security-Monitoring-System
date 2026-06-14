<?php

namespace App\Domains\Shifts\Models;

use App\Domains\Admins\Models\Admin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * WorkingTimeOverride Model - WTR Compliance Override Management
 *
 * Manages documented overrides to UK Working Time Regulations 1998:
 * - 12-hour duration warnings
 * - 11-hour rest period violations
 * - Admin approval and justification tracking
 * - Audit trail for regulatory compliance
 */
class WorkingTimeOverride extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'working_time_overrides';

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
     * Disable updated_at timestamps (table only has created_at).
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shift_id',
        'override_type',
        'justification',
        'approved_by',
        'approved_at',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Override type constants
     */
    const TYPE_DURATION_12HR = 'duration_12hr';
    const TYPE_REST_PERIOD_11HR = 'rest_period_11hr';

    /**
     * Get available override types.
     */
    public static function getOverrideTypes(): array
    {
        return [
            self::TYPE_DURATION_12HR => '12-Hour Duration Warning',
            self::TYPE_REST_PERIOD_11HR => '11-Hour Rest Period Violation'
        ];
    }

    /**
     * Get the shift this override belongs to.
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Get the admin who approved this override.
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    /**
     * Get formatted override type for display.
     */
    public function getFormattedTypeAttribute(): string
    {
        return self::getOverrideTypes()[$this->override_type] ?? ucfirst($this->override_type);
    }

    /**
     * Get override severity level.
     */
    public function getSeverityAttribute(): string
    {
        return match($this->override_type) {
            self::TYPE_DURATION_12HR => 'WARNING',
            self::TYPE_REST_PERIOD_11HR => 'WARNING',
            default => 'UNKNOWN'
        };
    }

    /**
     * Check if override was approved recently (within 24 hours).
     */
    public function isRecentlyApproved(): bool
    {
        return $this->approved_at && $this->approved_at->greaterThan(Carbon::now()->subDay());
    }

    /**
     * Get formatted approval time for display.
     */
    public function getFormattedApprovalTimeAttribute(): string
    {
        if (!$this->approved_at) {
            return 'Not approved';
        }

        return $this->approved_at->format('d/m/Y H:i');
    }

    /**
     * Scope for overrides by type.
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('override_type', $type);
    }

    /**
     * Scope for overrides approved by a specific admin.
     */
    public function scopeApprovedBy($query, string $adminId)
    {
        return $query->where('approved_by', $adminId);
    }

    /**
     * Scope for overrides within a date range.
     */
    public function scopeDateRange($query, Carbon $startDate, Carbon $endDate)
    {
        return $query->whereBetween('approved_at', [$startDate, $endDate]);
    }

    /**
     * Create an override with validation.
     */
    public static function createOverride(
        string $shiftId,
        string $overrideType,
        string $justification,
        string $approvedBy
    ): self {
        return self::create([
            'shift_id' => $shiftId,
            'override_type' => $overrideType,
            'justification' => trim($justification),
            'approved_by' => $approvedBy,
            'approved_at' => Carbon::now(),
        ]);
    }

    /**
     * Get override statistics for reporting.
     */
    public static function getOverrideStats(Carbon $fromDate, Carbon $toDate): array
    {
        $stats = self::selectRaw('
            override_type,
            COUNT(*) as count,
            COUNT(DISTINCT shift_id) as unique_shifts,
            COUNT(DISTINCT approved_by) as unique_admins
        ')
        ->whereBetween('approved_at', [$fromDate, $toDate])
        ->groupBy('override_type')
        ->get()
        ->keyBy('override_type');

        $overrideTypes = self::getOverrideTypes();
        $result = [];

        foreach ($overrideTypes as $type => $label) {
            $stat = $stats->get($type);
            $result[$type] = [
                'label' => $label,
                'count' => $stat ? $stat->count : 0,
                'unique_shifts' => $stat ? $stat->unique_shifts : 0,
                'unique_admins' => $stat ? $stat->unique_admins : 0
            ];
        }

        return [
            'period' => [
                'from' => $fromDate->toISOString(),
                'to' => $toDate->toISOString()
            ],
            'by_type' => $result,
            'total_overrides' => array_sum(array_column($result, 'count')),
            'total_shifts_affected' => $stats->sum('unique_shifts')
        ];
    }
}