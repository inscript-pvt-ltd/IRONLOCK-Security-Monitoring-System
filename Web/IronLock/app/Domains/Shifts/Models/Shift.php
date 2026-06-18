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
        'reference',
        'guard_id',
        'site_id',
        'geofence_id',
        'scheduled_start',
        'scheduled_end',
        'actual_start',
        'actual_end',
        'checked_in_at',
        'checkin_override_until',
        'late_authorized_by',
        'attendance_reason',
        'attendance_note',
        'ended_early',
        'resolved_at',
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
    /**
     * Derived flags surfaced to the dashboard calendar JSON so the front-end
     * does not have to re-derive the resolution rules. @see needsResolution().
     */
    protected $appends = [
        'needs_resolution',
        'resolution_kind',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'actual_start' => 'datetime',
            'actual_end' => 'datetime',
            'checked_in_at' => 'datetime',
            'checkin_override_until' => 'datetime',
            'ended_early' => 'boolean',
            'resolved_at' => 'datetime',
            'compliance_summary' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Shift status constants
     */
    const STATUS_SCHEDULED = 'scheduled';
    const STATUS_CHECKED_IN = 'checked_in';
    const STATUS_ACTIVE = 'active';
    const STATUS_COMPLETED = 'completed';
    const STATUS_CANCELLED = 'cancelled';
    const STATUS_MISSED = 'missed';

    /**
     * The configured check-in / start window, in minutes either side of
     * scheduled_start. One value drives the "too early" floor and the
     * "too late" ceiling. @see config/ironlock.php
     */
    public static function windowMinutes(): int
    {
        return (int) config('ironlock.check_in_window_minutes', 15);
    }

    /**
     * Assign a human-readable reference (SH-####) on create when one wasn't
     * supplied. Done in a model hook so EVERY shift gets a code regardless of
     * where it's created (controller, seeder, tinker) — the UUID `id` stays the
     * real key; this is display-only.
     */
    protected static function booted(): void
    {
        static::creating(function (Shift $shift) {
            if (empty($shift->reference)) {
                $shift->reference = self::generateReference();
            }
        });
    }

    /**
     * Next sequential reference, "SH-####", continuing from the highest existing
     * number (starts at SH-1001). Low-volume admin scheduling, so a max()+1 read
     * is sufficient; the UNIQUE index is the final guard against any collision.
     */
    public static function generateReference(): string
    {
        $last = static::query()
            ->whereNotNull('reference')
            ->orderByRaw('CAST(SUBSTRING(reference, 4) AS UNSIGNED) DESC')
            ->value('reference');

        $next = $last ? ((int) substr($last, 3)) + 1 : 1001;

        return 'SH-' . $next;
    }

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
     * Get the admin who authorized a late check-in on this shift, if any.
     */
    public function lateAuthorizer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'late_authorized_by');
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
     * Whether a per-shift late-check-in override granted by a supervisor is
     * still open. Lets a Missed (or re-opened) shift be checked in / started
     * past the normal window until checkin_override_until.
     */
    public function hasActiveOverride(): bool
    {
        return $this->checkin_override_until !== null
            && Carbon::now()->lessThanOrEqualTo($this->checkin_override_until);
    }

    /**
     * Whether the guard may sign in / check in right now. Open while a shift
     * is already active, or for a scheduled shift inside the ±window, or any
     * shift carrying an active supervisor override.
     */
    public function canCheckIn(): bool
    {
        if ($this->status === self::STATUS_ACTIVE) {
            return true;
        }

        if (!in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_CHECKED_IN, self::STATUS_MISSED], true)) {
            return false;
        }

        if ($this->hasActiveOverride()) {
            return true;
        }

        $window = self::windowMinutes();

        return Carbon::now()->between(
            $this->scheduled_start->copy()->subMinutes($window),
            $this->scheduled_start->copy()->addMinutes($window)
        );
    }

    /**
     * Check if shift can be started now. Startable from a scheduled or
     * checked-in state, any time up to scheduled_start + window (or while a
     * supervisor override is open).
     */
    public function canStart(): bool
    {
        if (!in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_CHECKED_IN], true)) {
            return false;
        }

        if ($this->hasActiveOverride()) {
            return true;
        }

        $window = self::windowMinutes();

        return Carbon::now()->between(
            $this->scheduled_start->copy()->subMinutes($window),
            $this->scheduled_start->copy()->addMinutes($window)
        );
    }

    /**
     * Whether the start/check-in window has expired for a shift that is not
     * yet active — i.e. now is past scheduled_start + window with no open
     * override. Used to surface the "contact your supervisor" message and to
     * drive the mark-missed sweep.
     */
    public function windowHasExpired(): bool
    {
        if (in_array($this->status, [self::STATUS_ACTIVE, self::STATUS_COMPLETED, self::STATUS_CANCELLED], true)) {
            return false;
        }

        if ($this->hasActiveOverride()) {
            return false;
        }

        return Carbon::now()->greaterThan(
            $this->scheduled_start->copy()->addMinutes(self::windowMinutes())
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
     * Move the shift to Checked-In when the guard signs in within the window.
     * Login ≠ start: this only records presence; pressing Start activates it.
     * No-op (returns false) once the shift is already checked in or beyond.
     */
    public function checkIn(): bool
    {
        if (!in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_MISSED], true)) {
            return false;
        }

        $this->update([
            'status' => self::STATUS_CHECKED_IN,
            'checked_in_at' => Carbon::now(),
        ]);

        return true;
    }

    /**
     * Mark the shift Missed because its check-in/start window expired without
     * the guard becoming active. Only valid from scheduled/checked-in.
     */
    public function markMissed(): bool
    {
        if (!in_array($this->status, [self::STATUS_SCHEDULED, self::STATUS_CHECKED_IN], true)) {
            return false;
        }

        // Clear any prior resolution: a freshly missed shift (e.g. one re-opened
        // by a late-authorization that then lapsed) needs supervisor attention
        // again, so it must show the red ⚠ until resolved anew.
        $this->update([
            'status' => self::STATUS_MISSED,
            'resolved_at' => null,
        ]);

        return true;
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

        // Set actual_end on the model before building the compliance snapshot so that
        // getActualDurationAttribute() sees the correct value inside generateComplianceSummary().
        $this->actual_end = Carbon::now();

        // Flag an early finish for supervisor resolution: the guard ended more
        // than one window (the same ±15-min grace used elsewhere) before the
        // scheduled end. This drives the red ⚠ on the schedule until resolved.
        $endedEarly = $this->actual_end->lessThan(
            $this->scheduled_end->copy()->subMinutes(self::windowMinutes())
        );

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'actual_end' => $this->actual_end,
            'ended_by' => $endedBy ?? 'mobile_app',
            'ended_early' => $endedEarly,
            'compliance_summary' => $this->generateComplianceSummary()
        ]);

        return true;
    }

    /**
     * Whether this shift needs supervisor resolution — i.e. it is unresolved
     * and either missed or ended early. Drives the red ⚠ on the schedule.
     */
    public function needsResolution(): bool
    {
        if ($this->resolved_at !== null) {
            return false;
        }

        return $this->status === self::STATUS_MISSED
            || ($this->status === self::STATUS_COMPLETED && (bool) $this->ended_early);
    }

    /**
     * Categorise why a shift needs resolution, for the dashboard info message:
     *  - 'never_checked_in'   : missed, the guard never signed in (window lapsed)
     *  - 'checked_in_no_start': missed, signed in but never started in the window
     *  - 'ended_early'        : completed before the scheduled end time
     *  - null                 : does not need resolution
     */
    public function resolutionKind(): ?string
    {
        if (!$this->needsResolution()) {
            return null;
        }

        if ($this->status === self::STATUS_MISSED) {
            return $this->checked_in_at !== null ? 'checked_in_no_start' : 'never_checked_in';
        }

        return 'ended_early';
    }

    /**
     * Accessor for the appended `needs_resolution` JSON attribute.
     */
    public function getNeedsResolutionAttribute(): bool
    {
        return $this->needsResolution();
    }

    /**
     * Accessor for the appended `resolution_kind` JSON attribute.
     */
    public function getResolutionKindAttribute(): ?string
    {
        return $this->resolutionKind();
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
            self::STATUS_CHECKED_IN => 'Checked-In',
            self::STATUS_ACTIVE => 'Active',
            self::STATUS_COMPLETED => 'Completed',
            self::STATUS_CANCELLED => 'Cancelled',
            self::STATUS_MISSED => 'Missed',
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
            self::STATUS_CHECKED_IN => 'amber',
            self::STATUS_ACTIVE => 'green',
            self::STATUS_COMPLETED => 'blue',
            self::STATUS_CANCELLED => 'red',
            self::STATUS_MISSED => 'red',
            default => 'gray'
        };
    }
}