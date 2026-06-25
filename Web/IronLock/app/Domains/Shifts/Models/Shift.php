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
        'end_type',
        'auto_closed',
        'end_reason',
        'end_note',
        'early_end_status',
        'early_end_reason',
        'early_end_note',
        'early_end_requested_at',
        'early_end_decided_at',
        'early_end_decided_by',
        'resolved_at',
        'totp_seed',
        'wakefulness_schedule',
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
        'can_recover_late',
        'early_end_request',
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
            'auto_closed' => 'boolean',
            'early_end_requested_at' => 'datetime',
            'early_end_decided_at' => 'datetime',
            'resolved_at' => 'datetime',
            'compliance_summary' => 'array',
            // Decrypted transparently on read; the column stores ciphertext so a
            // DB leak never exposes the offline TOTP seed (spec §9.4).
            'totp_seed' => 'encrypted',
            'wakefulness_schedule' => 'array',
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
     * How a completed shift was closed (BACKEND_SHIFT_END_SPEC §3).
     *  - guard : ended on/after scheduled_end via the app (trustworthy)
     *  - early : ended before scheduled_end, supervisor-approved (reason on file)
     *  - auto  : server force-closed an overdue shift (flag for supervisor)
     */
    const END_TYPE_GUARD = 'guard';
    const END_TYPE_EARLY = 'early';
    const END_TYPE_AUTO = 'auto';

    /**
     * Lifecycle of the single open early-end request on a shift.
     */
    const EARLY_END_PENDING = 'pending';
    const EARLY_END_APPROVED = 'approved';
    const EARLY_END_REJECTED = 'rejected';

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
     * Get the admin who approved/rejected the early-end request, if any.
     */
    public function earlyEndDecider(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'early_end_decided_by');
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
     * Whether a NORMAL (on-time) end is allowed right now. A guard may only end
     * their own shift once the contracted end time has passed — leaving before
     * scheduled_end requires the supervisor-approved early-end flow instead.
     * @see canEndEarly(), BACKEND_SHIFT_END_SPEC §1
     */
    public function canEnd(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->scheduled_end !== null
            && Carbon::now()->greaterThanOrEqualTo($this->scheduled_end);
    }

    /**
     * Whether the guard may request to leave early — an active shift that has
     * not yet reached its scheduled end. The request itself does not end the
     * shift; a supervisor must approve it first.
     */
    public function canRequestEarlyEnd(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->scheduled_end !== null
            && Carbon::now()->lessThan($this->scheduled_end);
    }

    /**
     * Whether an early end may be carried out now — i.e. there is a supervisor
     * APPROVED request on file. The server is the authority: a tampered client
     * cannot end early without this.
     */
    public function canEndEarly(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && $this->early_end_status === self::EARLY_END_APPROVED;
    }

    public function hasPendingEarlyEnd(): bool
    {
        return $this->early_end_status === self::EARLY_END_PENDING;
    }

    public function hasApprovedEarlyEnd(): bool
    {
        return $this->early_end_status === self::EARLY_END_APPROVED;
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
     *
     * Provisions the wakefulness machinery at start time (spec §9.4.1): a
     * per-shift TOTP seed (so the app can compute offline challenge codes) and a
     * randomised challenge schedule (so the app can fire matching offline local
     * notifications, and the server dispatcher knows when to push online ones).
     * Both are generated once and never regenerated on a restart.
     */
    public function start(?string $startedBy = null): bool
    {
        if (!$this->canStart()) {
            return false;
        }

        $startAt = Carbon::now();

        $attributes = [
            'status' => self::STATUS_ACTIVE,
            'actual_start' => $startAt,
            'started_by' => $startedBy ?? 'mobile_app',
        ];

        // Provision once. A seed/schedule already on file (e.g. a re-opened
        // shift) is kept so previously-delivered offline codes stay valid.
        if (empty($this->totp_seed)) {
            $attributes['totp_seed'] = \App\Domains\Wakefulness\Support\Totp::generateSecret();
        }
        if (empty($this->wakefulness_schedule)) {
            $attributes['wakefulness_schedule'] = $this->buildWakefulnessSchedule($startAt);
        }

        $this->update($attributes);

        return true;
    }

    /**
     * Build the randomised wakefulness challenge schedule for the live portion
     * of the shift: marks spaced a random 30–45 min apart (config-driven),
     * starting one gap after `$from` and stopping at scheduled_end. Returns an
     * array of ISO-8601 UTC timestamps — the single source of truth shared by
     * the server dispatcher and the app's offline notifications.
     */
    public function buildWakefulnessSchedule(Carbon $from): array
    {
        $minGap = (int) config('ironlock.wakefulness_min_gap_minutes', 30);
        $maxGap = (int) config('ironlock.wakefulness_max_gap_minutes', 45);
        if ($maxGap < $minGap) {
            $maxGap = $minGap;
        }

        $end = $this->scheduled_end ? $this->scheduled_end->copy() : $from->copy()->addHours(8);

        $schedule = [];
        $cursor = $from->copy()->addMinutes(random_int($minGap, $maxGap));

        // Cap the count so a misconfigured (e.g. multi-day) shift can't generate
        // an unbounded array; a normal shift produces well under this.
        while ($cursor->lessThan($end) && count($schedule) < 64) {
            $schedule[] = $cursor->copy()->utc()->format('Y-m-d\TH:i:s\Z');
            $cursor->addMinutes(random_int($minGap, $maxGap));
        }

        return $schedule;
    }

    /**
     * End an active shift.
     *
     * @param string|null $endedBy who/what closed it (defaults to mobile_app)
     * @param string      $endType END_TYPE_GUARD (normal) or END_TYPE_EARLY (approved)
     * @param string|null $reason  early-end reason (mirrors the approved request)
     * @param string|null $note    early-end free-text note
     */
    public function end(
        ?string $endedBy = null,
        string $endType = self::END_TYPE_GUARD,
        ?string $reason = null,
        ?string $note = null
    ): bool {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        // Set actual_end on the model before building the compliance snapshot so that
        // getActualDurationAttribute() sees the correct value inside generateComplianceSummary().
        $this->actual_end = Carbon::now();

        // Record whether this finished materially before the scheduled end (same
        // ±window grace used elsewhere) for reporting/payroll.
        $endedEarly = $this->actual_end->lessThan(
            $this->scheduled_end->copy()->subMinutes(self::windowMinutes())
        );

        $attributes = [
            'status' => self::STATUS_COMPLETED,
            'actual_end' => $this->actual_end,
            'ended_by' => $endedBy ?? 'mobile_app',
            'ended_early' => $endedEarly,
            'end_type' => $endType,
            'end_reason' => $reason,
            'end_note' => $note,
            'compliance_summary' => $this->generateComplianceSummary(),
        ];

        // A pre-approved early end has already been signed off by a supervisor —
        // the approval IS its resolution, so stamp resolved_at to keep it off the
        // red ⚠ list rather than asking them to resolve it a second time.
        if ($endType === self::END_TYPE_EARLY) {
            $attributes['resolved_at'] = $this->actual_end;
        }

        $this->update($attributes);

        return true;
    }

    /**
     * Server-forced close of an overdue shift the guard never ended
     * (BACKEND_SHIFT_END_SPEC §2). actual_end is set to scheduled_end — the
     * contracted boundary — because there is no trustworthy signal the guard
     * stayed on past it; crediting time up to "now" would over-pay coverage.
     * Flagged auto_closed so it surfaces in supervisor reporting.
     */
    public function autoClose(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        $this->actual_end = $this->scheduled_end;

        $this->update([
            'status' => self::STATUS_COMPLETED,
            'actual_end' => $this->actual_end,
            'ended_by' => 'system_auto_close',
            'ended_early' => false,
            'end_type' => self::END_TYPE_AUTO,
            'auto_closed' => true,
            'compliance_summary' => $this->generateComplianceSummary(),
        ]);

        return true;
    }

    /**
     * Record (or overwrite) a guard's request to leave early. One open request
     * per shift: a re-request after a rejection simply replaces the prior one.
     * Does NOT end the shift — it stays active until a supervisor approves and
     * the guard then ends it.
     */
    public function requestEarlyEnd(string $reason, ?string $note = null): bool
    {
        if (!$this->canRequestEarlyEnd()) {
            return false;
        }

        $this->update([
            'early_end_status' => self::EARLY_END_PENDING,
            'early_end_reason' => $reason,
            'early_end_note' => $note,
            'early_end_requested_at' => Carbon::now(),
            'early_end_decided_at' => null,
            'early_end_decided_by' => null,
        ]);

        return true;
    }

    /**
     * Apply a supervisor's decision to the pending early-end request. This is
     * how the guard's phone learns the outcome (it polls GET /shifts/current).
     *
     * @param string      $decision EARLY_END_APPROVED or EARLY_END_REJECTED
     * @param string|null $adminId  the deciding supervisor
     */
    public function decideEarlyEnd(string $decision, ?string $adminId = null): bool
    {
        if ($this->early_end_status !== self::EARLY_END_PENDING) {
            return false;
        }

        $this->update([
            'early_end_status' => $decision,
            'early_end_decided_at' => Carbon::now(),
            'early_end_decided_by' => $adminId,
        ]);

        return true;
    }

    /**
     * The early-end request object exposed on the shift payload (§0.3), or null
     * when there is no outstanding request. Once completed there is nothing the
     * app needs to act on, so it is suppressed.
     */
    public function earlyEndRequestPayload(): ?array
    {
        if ($this->early_end_status === null || $this->status === self::STATUS_COMPLETED) {
            return null;
        }

        return [
            'status' => $this->early_end_status,
            'reason' => $this->early_end_reason,
            'note' => $this->early_end_note,
            'requested_at' => optional($this->early_end_requested_at)->toISOString(),
            'decided_at' => optional($this->early_end_decided_at)->toISOString(),
            'decided_by' => $this->early_end_decided_by,
        ];
    }

    /**
     * Accessor for the appended `early_end_request` JSON attribute.
     */
    public function getEarlyEndRequestAttribute(): ?array
    {
        return $this->earlyEndRequestPayload();
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
     * Whether a missed shift can still be recovered by bringing a guard in now
     * (Authorize late check-in / Reassign). Only true while there is still
     * shift time left to work — i.e. the scheduled end is in the future. Once
     * the shift's window is fully past, the only sensible outcomes are Excuse
     * or Confirm no-show, so those recovery options must be withdrawn.
     */
    public function canRecoverLate(): bool
    {
        return $this->status === self::STATUS_MISSED
            && $this->scheduled_end !== null
            && Carbon::now()->lessThan($this->scheduled_end);
    }

    /**
     * Accessor for the appended `needs_resolution` JSON attribute.
     */
    public function getNeedsResolutionAttribute(): bool
    {
        return $this->needsResolution();
    }

    /**
     * Accessor for the appended `can_recover_late` JSON attribute.
     */
    public function getCanRecoverLateAttribute(): bool
    {
        return $this->canRecoverLate();
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
            // Photo verification tally at end-of-shift. Reviews recorded after
            // this snapshot are reflected live on the timeline; the report keeps
            // this point-in-time record.
            'photo_verification' => $this->photoVerificationTally(),
            'generated_at' => Carbon::now()->toISOString()
        ];

        return $summary;
    }

    /**
     * Photo-verification counts for the compliance summary: how many requests
     * were raised/fulfilled and how many fulfilled photos an admin has reviewed
     * (approved / rejected). A snapshot at end-of-shift; the timeline recomputes
     * it live for reviews recorded later.
     */
    public function photoVerificationTally(): array
    {
        $requests = \App\Domains\Photos\Models\PhotoRequest::where('shift_id', $this->id)->count();
        $fulfilled = \App\Domains\Photos\Models\PhotoRequest::where('shift_id', $this->id)
            ->where('status', \App\Domains\Photos\Models\PhotoRequest::STATUS_FULFILLED)
            ->count();

        $approved = \App\Domains\Photos\Models\PhotoReview::where('shift_id', $this->id)
            ->where('decision', \App\Domains\Photos\Models\PhotoReview::DECISION_APPROVED)
            ->count();
        $rejected = \App\Domains\Photos\Models\PhotoReview::where('shift_id', $this->id)
            ->where('decision', \App\Domains\Photos\Models\PhotoReview::DECISION_REJECTED)
            ->count();
        $reviewed = $approved + $rejected;

        return [
            'requests' => $requests,
            'fulfilled' => $fulfilled,
            'reviewed' => $reviewed,
            'approved' => $approved,
            'rejected' => $rejected,
            'unreviewed' => max(0, $fulfilled - $reviewed),
        ];
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