<?php

namespace App\Domains\Shifts\Models;

use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use App\Domains\Sites\Models\Site;
use App\Domains\Geofences\Models\Geofence;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Shift Model - Guard Scheduling with WTR Compliance
 *
 * Manages guard shift scheduling and lifecycle with:
 * - Working Time Regulations 1998 compliance
 * - Site and geofence assignment
 * - Real-time status tracking
 * - TOTP seed provisioning for offline functionality
 * - Compliance summary generation
 */
class Shift extends Model
{
    use HasUuids;

    /**
     * The table associated with the model.
     */
    protected $table = 'shifts';

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
        'site_id',
        'geofence_id',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'totp_seed',
        'status',
        'started_by',
        'ended_by',
        'override_reason',
        'compliance_summary',
        'created_by',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'compliance_summary' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Shift status constants
     */
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * Get the guard assigned to this shift.
     */
    public function assignedGuard(): BelongsTo
    {
        return $this->belongsTo(Guard::class, 'guard_id');
    }

    /**
     * Get the site for this shift.
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    /**
     * Get the geofence for this shift.
     */
    public function geofence(): BelongsTo
    {
        return $this->belongsTo(Geofence::class);
    }

    /**
     * Get the admin who created this shift.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    /**
     * Get working time regulation overrides for this shift.
     */
    public function workingTimeOverrides(): HasMany
    {
        return $this->hasMany(WorkingTimeOverride::class);
    }

    /**
     * Get shift events (GPS pings, wakefulness checks, etc.)
     */
    public function events(): HasMany
    {
        return $this->hasMany(\App\Domains\Shifts\Models\ShiftEvent::class);
    }

    /**
     * Scope for active shifts only.
     */
    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope for scheduled shifts only.
     */
    public function scopeScheduled($query)
    {
        return $query->where('status', self::STATUS_SCHEDULED);
    }

    /**
     * Scope for shifts within date range.
     */
    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('scheduled_start', [$startDate, $endDate]);
    }

    /**
     * Check if shift is currently active.
     */
    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Check if shift can be started now.
     */
    public function canStart(): bool
    {
        return $this->status === self::STATUS_SCHEDULED &&
               Carbon::now()->between(
                   $this->scheduled_start->subMinutes(15),
                   $this->scheduled_end
               );
    }

    /**
     * Check if shift can be ended now.
     */
    public function canEnd(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Start the shift.
     */
    public function start(?string $startedBy = null): bool
    {
        if (!$this->canStart()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_ACTIVE,
            'actual_start' => Carbon::now(),
            'started_by' => $startedBy ?? 'mobile_app'
        ]);

        return true;
    }

    /**
     * End the shift.
     */
    public function end(?string $endedBy = null): bool
    {
        if (!$this->canEnd()) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'actual_end' => Carbon::now(),
            'ended_by' => $endedBy ?? 'mobile_app',
            'compliance_summary' => $this->generateComplianceSummary()
        ]);

        return true;
    }

    /**
     * Get scheduled duration in hours.
     */
    public function getScheduledDurationAttribute(): float
    {
        return $this->scheduled_start->diffInHours($this->scheduled_end, true);
    }

    /**
     * Get actual duration in hours (if completed).
     */
    public function getActualDurationAttribute(): ?float
    {
        if (!$this->actual_start || !$this->actual_end) {
            return null;
        }

        return $this->actual_start->diffInHours($this->actual_end, true);
    }

    /**
     * Get rest period since last shift in hours.
     */
    public function getRestPeriodSinceLastShiftAttribute(): ?float
    {
        $lastShift = self::where('guard_id', $this->guard_id)
            ->where('status', '!=', self::STATUS_CANCELLED)
            ->where('id', '!=', $this->id)
            ->where('scheduled_end', '<', $this->scheduled_start)
            ->orderBy('scheduled_end', 'desc')
            ->first();

        if (!$lastShift) {
            return null;
        }

        return Carbon::parse($lastShift->scheduled_end)->diffInHours($this->scheduled_start, true);
    }

    /**
     * Check if shift violates Working Time Regulations.
     */
    public function hasWTRViolations(): array
    {
        $violations = [];

        // Check duration
        if ($this->scheduled_duration > 16) {
            $violations[] = [
                'type' => 'DURATION_16HR_EXCEEDED',
                'severity' => 'ERROR',
                'message' => 'Shift duration exceeds 16-hour maximum'
            ];
        } elseif ($this->scheduled_duration > 12) {
            $violations[] = [
                'type' => 'DURATION_12HR_WARNING',
                'severity' => 'WARNING',
                'message' => 'Shift duration exceeds 12-hour recommendation'
            ];
        }

        // Check rest period
        $restPeriod = $this->rest_period_since_last_shift;
        if ($restPeriod !== null && $restPeriod < 11) {
            $violations[] = [
                'type' => 'REST_PERIOD_11HR',
                'severity' => 'WARNING',
                'message' => "Insufficient rest period: {$restPeriod} hours (11 hours required)"
            ];
        }

        return $violations;
    }

    /**
     * Generate compliance summary for completed shift.
     */
    public function generateComplianceSummary(): array
    {
        $summary = [
            'shift_duration' => [
                'scheduled_hours' => $this->scheduled_duration,
                'actual_hours' => $this->actual_duration,
            ],
            'wtr_compliance' => [
                'violations' => $this->hasWTRViolations(),
                'overrides_used' => $this->workingTimeOverrides()->count()
            ],
            'attendance' => [
                'started_on_time' => $this->actual_start && $this->actual_start <= $this->scheduled_start->addMinutes(15),
                'ended_on_time' => $this->actual_end && $this->actual_end >= $this->scheduled_end->subMinutes(15)
            ],
            'generated_at' => Carbon::now()->toISOString()
        ];

        return $summary;
    }

    /**
     * Get formatted status for display.
     */
    public function getFormattedStatusAttribute(): string
    {
        return match($this->status) {
            self::STATUS_SCHEDULED => 'Scheduled',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            default => ucfirst($this->status)
        };
    }

    /**
     * Get status color for UI display.
     */
    public function getStatusColorAttribute(): string
    {
        return match($this->status) {
            self::STATUS_SCHEDULED => 'blue',
            self::STATUS_ACTIVE => 'green',
            self::STATUS_COMPLETED => 'gray',
            self::STATUS_CANCELLED => 'red',
            default => 'gray'
        };
    }
}