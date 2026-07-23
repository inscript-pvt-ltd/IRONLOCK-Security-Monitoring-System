<?php

namespace App\Domains\GPS\Services;

use App\Domains\Alerts\Services\AlertService;
use App\Domains\Geofences\Services\GeofenceService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Sites\Models\Site;
use App\Events\GuardLocationUpdated;
use App\Jobs\CheckZoneExitJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * GPSTrackingService — ingest live guard positions and drive zone logic.
 *
 * One mutable row per guard (UPSERT, no history). Each ping runs a server-side
 * ST_CONTAINS check via GeofenceService to get a RAW zone reading. A reading is
 * not trusted to flip the confirmed zone_status on its own: GPS jitter near the
 * boundary is debounced through a confirmation window (ironlock.
 * zone_exit_confirm_seconds) — a status change must PERSIST that long before it
 * commits, writes a ZONE_TRANSITION and (on an exit) schedules a grace-period
 * CheckZoneExitJob. A flicker that reverts inside the window leaves no trace and
 * never resets the excursion anchor. The grace deadline is measured from the
 * guard's real step-out moment, so the confirmation window is absorbed into the
 * grace (a 5-min grace stays 5 min), not added on top. The job — not this method
 * — decides whether to raise a ZONE_EXIT alert. The client clock is never
 * trusted: `recorded_at` is kept for diagnostics only and `updated_at` (the
 * server's authoritative "last seen") is what the dashboard and COMMS_INTERRUPTED
 * logic rely on.
 *
 * Debounce is a LIVE-tick behaviour only. The controller routes a request by
 * the guard's pre-batch state: a fresh same-shift last-seen is a live tick
 * (debounced, dispatches inline on a confirmed exit); a stale/absent/cross-shift
 * last-seen is a reconnect (replayed immediately, decided once by finalizeFlush).
 *
 * Offline backfill (Phase 7): when an app reconnects it flushes buffered pings
 * in one batch (§6.1). The flush is recorded for audit ping-by-ping, but live
 * alerting is decided ONCE on the net result — see recordLocation()'s
 * $dispatchZoneCheck flag and finalizeFlush(). Historical pings never page a
 * supervisor retroactively (roadmap §7.3); a comms gap is recorded as an
 * explicit COMMS_GAP_START / COMMS_GAP_END pair plus a SYNC_FLUSH summary.
 */
class GPSTrackingService
{
    public function __construct(
        private readonly GeofenceService $geofenceService,
        private readonly AlertService $alertService,
    ) {}

    /**
     * The guard's pre-flush state, read ONCE before a batch is applied. Used by
     * finalizeFlush() to judge the net zone transition and the comms-gap window
     * from server-side facts (never the client clock).
     *
     * `shift_id` is returned so finalizeFlush() can tell whether this snapshot
     * belongs to the shift being flushed. guard_locations keeps one mutable row
     * per guard with no history, so a leftover row from a PRIOR shift would
     * otherwise be mistaken for this shift's prior state.
     *
     * @return array{shift_id: ?string, zone_status: ?string, last_seen_at: ?Carbon}
     */
    public function flushPreState(string $guardId): array
    {
        $row = GuardLocation::where('guard_id', $guardId)->first();

        return [
            'shift_id' => $row?->shift_id,
            'zone_status' => $row?->zone_status,
            'last_seen_at' => $row?->updated_at,
        ];
    }

    /**
     * Record one GPS ping for a guard, updating their live location row and
     * (per-ping) appending the immutable ZONE_TRANSITION audit trail. Returns the
     * persisted GuardLocation.
     *
     * $dispatchZoneCheck selects the zone-state path. TRUE is the LIVE tick: the
     * change is debounced through the confirmation window (confirmZoneState) and,
     * once a change CONFIRMS, a fresh exit schedules the grace-period job inline.
     * FALSE is the REPLAY path for a reconnect flush (replayZoneState): each raw
     * edge commits immediately for a faithful audit trail but never pages here —
     * the single live decision is deferred to finalizeFlush() so a buffered
     * backlog logs its history without paging retroactively for events already
     * over. The caller (GPSController) picks the path per request: a fresh
     * same-shift last-seen is a live tick; a stale/absent/cross-shift one is a
     * reconnect. Both paths keep the ZONE_TRANSITION audit trail complete.
     */
    public function recordLocation(
        string $guardId,
        string $shiftId,
        array $locationData,
        bool $dispatchZoneCheck = true
    ): GuardLocation {
        $now = Carbon::now();

        // Read the guard's live row before the UPSERT overwrites it, to detect a
        // change against the prior CONFIRMED state. guard_locations is a single
        // mutable row per guard (no history), so a row left over from a PREVIOUS
        // shift must never be read as this shift's prior state — that would log a
        // phantom cross-shift transition on the first ping. Scope by shift_id, and
        // with no same-shift prior fall back to the shift-start baseline of
        // INSIDE_ZONE (the guard is expected at their post), so a guard already
        // OUTSIDE on their first fix registers a real INSIDE→OUTSIDE change.
        $previous = GuardLocation::where('guard_id', $guardId)->first();
        $prevSameShift = $previous && $previous->shift_id === $shiftId;
        $confirmedZoneStatus = $prevSameShift
            ? $previous->zone_status
            : GeofenceService::STATUS_INSIDE_ZONE;

        // Resolve the RAW zone reading for this fix (server-side spatial check).
        // "Raw" because a single reading is not yet trusted to flip the confirmed
        // status — jitter near the boundary is debounced below.
        $shift = Shift::with('site')->find($shiftId);
        $geofenceId = $shift?->geofence_id;
        $rawZoneStatus = GeofenceService::STATUS_INSIDE_ZONE;

        if ($geofenceId) {
            $isInside = $this->geofenceService->isInsideZone(
                $geofenceId,
                (float) $locationData['latitude'],
                (float) $locationData['longitude']
            );

            $rawZoneStatus = $isInside
                ? GeofenceService::STATUS_INSIDE_ZONE
                : GeofenceService::STATUS_OUTSIDE_ZONE;
        }

        // Decide the persisted state. The LIVE path debounces a change through the
        // confirmation window (jitter rejection + a stable grace clock); the flush
        // REPLAY path keeps the legacy immediate-commit behaviour and defers the
        // single live decision to finalizeFlush().
        $state = $dispatchZoneCheck
            ? $this->confirmZoneState($previous, $prevSameShift, $confirmedZoneStatus, $rawZoneStatus, $now)
            : $this->replayZoneState($previous, $prevSameShift, $confirmedZoneStatus, $rawZoneStatus, $now);

        // UPSERT: replace the single live-location row for this guard.
        // `updated_at` is the authoritative "last seen" time that drives
        // GuardLocation::isCommsInterrupted(). It is set explicitly in PHP (UTC)
        // rather than left to the column's ON UPDATE CURRENT_TIMESTAMP default,
        // because the DB session timezone is not guaranteed to be UTC — relying
        // on the DB clock would drift the 30s comms threshold (project tz gotcha).
        $location = GuardLocation::updateOrCreate(
            ['guard_id' => $guardId],
            [
                'shift_id' => $shiftId,
                'latitude' => $locationData['latitude'],
                'longitude' => $locationData['longitude'],
                'accuracy' => $locationData['accuracy'] ?? null,
                'battery_level' => $locationData['battery_level'] ?? null,
                'zone_status' => $state['zone_status'],
                'zone_left_at' => $state['zone_left_at'],
                'pending_zone_status' => $state['pending_zone_status'],
                'pending_zone_since' => $state['pending_zone_since'],
                'pending_is_shift_start' => $state['pending_is_shift_start'],
                'recorded_at' => isset($locationData['recorded_at'])
                    ? Carbon::parse($locationData['recorded_at'])
                    : null,
                'updated_at' => Carbon::now(),
            ]
        );

        // A CONFIRMED status change writes the immutable ZONE_TRANSITION audit row
        // (always — the timeline stays complete even for a replayed backlog) and,
        // on a confirmed exit from the LIVE path, schedules the delayed grace job.
        // The job re-confirms present state before it ever raises an alert.
        if ($state['committed_to'] !== null) {
            $this->logZoneTransitionEvent(
                $guardId,
                $shiftId,
                $geofenceId,
                $state['committed_from'],
                $state['committed_to'],
                (float) $locationData['latitude'],
                (float) $locationData['longitude'],
                $now
            );

            if ($state['dispatch_exit']) {
                $this->dispatchZoneExitCheck(
                    $shift,
                    $guardId,
                    $shiftId,
                    $state['zone_left_at'] ?? $now,
                    isShiftStartPositioning: $state['is_shift_start']
                );
            }
        }

        // Push to the dashboard. Soft-fail until broadcasting is configured so a
        // missing driver never blocks the ping.
        try {
            event(new GuardLocationUpdated($guardId, $shiftId, $location));
        } catch (\Throwable $e) {
            Log::debug('GuardLocationUpdated broadcast skipped', ['error' => $e->getMessage()]);
        }

        return $location;
    }

    /**
     * Close out a flush once, after every ping in it has been applied. Two jobs:
     *
     *  1. Live zone-exit — dispatch the grace-period check ONLY when the net
     *     effect of the flush is a *present* breach: the guard is OUTSIDE now and
     *     was INSIDE before the flush. This mirrors the standalone-ping
     *     INSIDE→OUTSIDE edge while collapsing a whole backlog to a single
     *     decision: a guard who exited and returned while offline raises nothing;
     *     one already outside before the flush is not re-paged; one still outside
     *     now is alerted on present state (the job re-confirms before raising).
     *
     *     This runs ONLY for a reconnect/replay batch. A live tick (fresh
     *     same-shift last-seen) is debounced ping-by-ping in recordLocation(),
     *     which dispatches the grace job inline on a CONFIRMED exit — so the
     *     caller passes $dispatchPresentState = false there to avoid a second,
     *     mis-classified dispatch. The comms-gap audit (2) always runs.
     *
     *  2. Comms-gap audit — if the last server-receipt before this flush is older
     *     than the backfill threshold, this flush is a RECONNECT: record an
     *     explicit COMMS_GAP_START / COMMS_GAP_END pair and a SYNC_FLUSH summary
     *     so the offline window is legible on the timeline. Boundaries are
     *     server-determined (START = last receipt, END = now). SCOPED to the
     *     current shift: a pre-snapshot left over from a prior shift is ignored,
     *     so the downtime *between* two shifts is never bridged into one bogus
     *     offline window (that stale row is not a comms interruption).
     *
     * @param  array{shift_id: ?string, zone_status: ?string, last_seen_at: ?Carbon}  $pre
     * @param  bool  $dispatchPresentState  Whether to make the net present-state
     *   zone-exit decision here. True for a reconnect/replay batch; false for a
     *   live tick, where recordLocation() already dispatched inline on a confirmed
     *   exit. The comms-gap audit runs regardless.
     */
    public function finalizeFlush(
        string $guardId,
        string $shiftId,
        array $pre,
        int $pingsApplied,
        bool $dispatchPresentState = true
    ): void {
        $now = Carbon::now();
        $current = GuardLocation::where('guard_id', $guardId)->first();

        // guard_locations holds ONE row per guard with no history, so the
        // pre-flush snapshot may belong to a PREVIOUS shift. Only trust it as
        // this shift's prior state when the shift_id matches.
        $preIsSameShift = ($pre['shift_id'] ?? null) === $shiftId;

        // (1) Present-state zone-exit — net INSIDE→OUTSIDE only. For a fresh
        // shift (no same-shift snapshot) the baseline is INSIDE — the guard is
        // expected at their post — so a first flush that leaves them OUTSIDE
        // still schedules the grace-period check (which re-confirms before
        // alerting), mirroring the standalone first-ping edge.
        $preZoneStatus = $preIsSameShift
            ? ($pre['zone_status'] ?? null)
            : GeofenceService::STATUS_INSIDE_ZONE;

        if ($dispatchPresentState
            && $current
            && $current->zone_status === GeofenceService::STATUS_OUTSIDE_ZONE
            && $preZoneStatus === GeofenceService::STATUS_INSIDE_ZONE) {
            $shift = Shift::with('site')->find($shiftId);

            // No same-shift prior state => this flush is the guard's first
            // established position for the shift: shift-start positioning, judged
            // against scheduled_start. Otherwise it's a mid-shift excursion, judged
            // against the site grace period. The excursion anchor maintained by
            // recordLocation is the job's token (fall back to now if absent).
            $this->dispatchZoneExitCheck(
                $shift,
                $guardId,
                $shiftId,
                $current->zone_left_at ?? $now,
                isShiftStartPositioning: !$preIsSameShift
            );
        }

        // (2) Comms-gap / reconnect audit — ONLY within a single shift. A stale
        // last-seen from a prior shift must not be reported as an offline gap
        // (this is the cross-shift "46h offline" phantom fix).
        $lastSeen = $pre['last_seen_at'] ?? null;
        if ($preIsSameShift && $lastSeen instanceof Carbon) {
            $gapSeconds = $lastSeen->diffInSeconds($now);
            $threshold = (int) config('ironlock.gps_backfill_threshold_seconds', 60);

            if ($gapSeconds > $threshold) {
                $this->logCommsGap($guardId, $shiftId, $lastSeen, $now, $gapSeconds, $pingsApplied);
            }
        }
    }

    /**
     * Schedule the delayed CheckZoneExitJob for an INSIDE->OUTSIDE edge.
     *
     * Two deadlines, one mechanism:
     *  - Shift-start positioning (the guard's FIRST established position of the
     *    shift is outside): the guard is expected inside by scheduled_start, so
     *    the check fires AT scheduled_start — immediately when it has already
     *    passed (a late start is alerted at once) and delayed until then for an
     *    early start (the guard may be en route until their scheduled time). The
     *    site grace period does NOT apply here — the check-in window already is
     *    the tolerance.
     *  - Mid-shift excursion (the guard was at post, then stepped out): the
     *    per-site grace_period_minutes to return before paging.
     *
     * $zoneLeftAt is the excursion anchor the job re-checks against the live row
     * so a superseded (returned-then-re-exited) excursion never fires.
     */
    private function dispatchZoneExitCheck(
        ?Shift $shift,
        string $guardId,
        string $shiftId,
        Carbon $zoneLeftAt,
        bool $isShiftStartPositioning
    ): void {
        $now = Carbon::now();

        if ($isShiftStartPositioning) {
            $scheduledStart = $shift?->scheduled_start;
            $fireAt = ($scheduledStart && $scheduledStart->isFuture())
                ? $scheduledStart->copy()
                : $now->copy();
            $reason = CheckZoneExitJob::REASON_SHIFT_START;
        } else {
            $gracePeriodMinutes = (int) ($shift?->site?->grace_period_minutes ?? Site::DEFAULT_GRACE_PERIOD_MINUTES);
            // Measure the grace from the guard's REAL step-out moment (the
            // excursion anchor), not from now, so the confirmation window is
            // absorbed into the grace rather than added on top. A late commit
            // (e.g. sparse pings) can land this in the past — delay() then simply
            // dispatches immediately, which is correct (grace already elapsed).
            $fireAt = $zoneLeftAt->copy()->addMinutes($gracePeriodMinutes);
            $reason = CheckZoneExitJob::REASON_MID_SHIFT;
        }

        CheckZoneExitJob::dispatch($guardId, $shiftId, $zoneLeftAt->toISOString(), $reason)
            ->delay($fireAt);
    }

    /**
     * LIVE zone-state decision with GPS-jitter debounce.
     *
     * A raw reading that differs from the guard's CONFIRMED zone_status becomes a
     * *candidate*; it only commits — flipping zone_status, writing a
     * ZONE_TRANSITION and (on an exit) scheduling the grace job — once it has
     * persisted for ironlock.zone_exit_confirm_seconds. A reading that matches the
     * confirmed status cancels any candidate, so a brief flicker across the
     * boundary leaves no trace and never resets the excursion anchor.
     *
     * The excursion anchor (zone_left_at) is set from the candidate's birth time
     * — the guard's REAL step-out moment — so the grace deadline runs from the
     * actual exit and the confirmation window is absorbed into the grace.
     *
     * @return array{
     *   zone_status: string, zone_left_at: ?Carbon, pending_zone_status: ?string,
     *   pending_zone_since: ?Carbon, pending_is_shift_start: ?bool,
     *   committed_from: ?string, committed_to: ?string, dispatch_exit: bool,
     *   is_shift_start: bool
     * }
     */
    private function confirmZoneState(
        ?GuardLocation $previous,
        bool $prevSameShift,
        string $confirmedZoneStatus,
        string $rawZoneStatus,
        Carbon $now
    ): array {
        $outside = GeofenceService::STATUS_OUTSIDE_ZONE;

        // Prior candidate + anchor count only when the row is this shift's (a
        // stale cross-shift row carries neither forward).
        $prevPendingStatus = $prevSameShift ? $previous?->pending_zone_status : null;
        $prevPendingSince = $prevSameShift ? $previous?->pending_zone_since : null;
        $prevPendingIsShiftStart = $prevSameShift ? $previous?->pending_is_shift_start : null;
        $prevAnchor = ($prevSameShift && $confirmedZoneStatus === $outside)
            ? $previous?->zone_left_at
            : null;

        // Baseline: no change. Preserve the anchor while confirmed OUTSIDE.
        $state = [
            'zone_status' => $confirmedZoneStatus,
            'zone_left_at' => $confirmedZoneStatus === $outside ? ($prevAnchor ?? $now) : null,
            'pending_zone_status' => null,
            'pending_zone_since' => null,
            'pending_is_shift_start' => null,
            'committed_from' => null,
            'committed_to' => null,
            'dispatch_exit' => false,
            'is_shift_start' => false,
        ];

        // (a) Reading matches confirmed status => stable, or a flicker reverted.
        // Cancel any candidate; nothing logged, anchor untouched.
        if ($rawZoneStatus === $confirmedZoneStatus) {
            return $state;
        }

        // (b) Reading differs. Continue the same candidate, or start a new one.
        // A candidate's "is shift start" is fixed at birth: true when the guard's
        // first established fix of the shift is outside (no same-shift prior).
        $sameCandidate = $prevPendingStatus === $rawZoneStatus && $prevPendingSince instanceof Carbon;
        $candidateSince = $sameCandidate ? $prevPendingSince : $now;
        $isShiftStart = $sameCandidate
            ? (bool) $prevPendingIsShiftStart
            : ($rawZoneStatus === $outside && !$prevSameShift);

        $confirmSeconds = (int) config('ironlock.zone_exit_confirm_seconds', 60);

        if ($candidateSince->diffInSeconds($now) < $confirmSeconds) {
            // Still within the window — hold the candidate, keep confirmed state.
            $state['pending_zone_status'] = $rawZoneStatus;
            $state['pending_zone_since'] = $candidateSince;
            $state['pending_is_shift_start'] = $rawZoneStatus === $outside ? $isShiftStart : null;
            return $state;
        }

        // (c) Candidate persisted long enough => COMMIT the change.
        $state['zone_status'] = $rawZoneStatus;
        $state['committed_from'] = $confirmedZoneStatus;
        $state['committed_to'] = $rawZoneStatus;

        if ($rawZoneStatus === $outside) {
            // Anchor the excursion to the guard's real step-out moment so the
            // grace runs from there (confirm window absorbed into grace).
            $state['zone_left_at'] = $candidateSince;
            $state['dispatch_exit'] = true;
            $state['is_shift_start'] = $isShiftStart;
        } else {
            // Confirmed return — end the excursion.
            $state['zone_left_at'] = null;
        }

        return $state;
    }

    /**
     * REPLAY (offline-backfill) zone-state decision — legacy immediate commit.
     *
     * A buffered backlog is applied ping-by-ping for a faithful audit trail, so
     * each raw INSIDE<->OUTSIDE edge commits at once and is logged; the single
     * *live* alert decision is deferred to finalizeFlush() (present net state) and
     * never paged retroactively. Debounce is intentionally NOT applied here — it
     * would need the client clock to measure persistence across buffered
     * timestamps — and the pending fields are cleared so LIVE tracking resumes
     * from a clean baseline.
     *
     * Grace anchor across an offline gap: if the pre-flush row shows an exit
     * candidate that was still FORMING when the device dropped (pending OUTSIDE,
     * not yet committed), the fresh exit committed here inherits that candidate's
     * real step-out time as its anchor rather than `now`, so the grace clock
     * counts the offline time already elapsed (remaining grace, not a fresh one).
     *
     * @return array matching confirmZoneState()'s shape (dispatch_exit always
     *               false — replay never pages live).
     */
    private function replayZoneState(
        ?GuardLocation $previous,
        bool $prevSameShift,
        string $confirmedZoneStatus,
        string $rawZoneStatus,
        Carbon $now
    ): array {
        $outside = GeofenceService::STATUS_OUTSIDE_ZONE;

        // Preserve the anchor across consecutive OUTSIDE pings, set it fresh on a
        // new exit, clear it on return — mirrors the pre-debounce behaviour.
        if ($rawZoneStatus === $outside) {
            if ($prevSameShift && $previous?->zone_status === $outside && $previous?->zone_left_at) {
                // Continuing an already-committed excursion — keep its anchor.
                $zoneLeftAt = $previous->zone_left_at;
            } elseif ($prevSameShift
                && $previous?->zone_status !== $outside
                && $previous?->pending_zone_status === $outside
                && $previous?->pending_zone_since instanceof Carbon) {
                // The guard had stepped out and the LIVE exit candidate was still
                // forming (not yet committed) when the device went offline, so the
                // debounce never got the post-window ping it needed to commit. Anchor
                // the excursion to that real, server-observed step-out moment instead
                // of resetting to `now`, so the grace period counts the time already
                // elapsed during the gap — only the REMAINING grace applies on
                // reconnect, not a fresh full one. Mirrors the live path's "grace
                // from real step-out" (zone-transition debounce).
                $zoneLeftAt = $previous->pending_zone_since;
            } else {
                $zoneLeftAt = $now;
            }
        } else {
            $zoneLeftAt = null;
        }

        $committed = $confirmedZoneStatus !== $rawZoneStatus;

        return [
            'zone_status' => $rawZoneStatus,
            'zone_left_at' => $zoneLeftAt,
            'pending_zone_status' => null,
            'pending_zone_since' => null,
            'pending_is_shift_start' => null,
            'committed_from' => $committed ? $confirmedZoneStatus : null,
            'committed_to' => $committed ? $rawZoneStatus : null,
            'dispatch_exit' => false,
            'is_shift_start' => false,
        ];
    }

    /**
     * The guard's current live position, or null if none recorded yet.
     */
    public function getCurrentLocation(string $guardId): ?GuardLocation
    {
        return GuardLocation::where('guard_id', $guardId)->first();
    }

    /**
     * Point-in-polygon check delegated to the geofence service.
     */
    public function isWithinGeofence(float $latitude, float $longitude, string $geofenceId): bool
    {
        return $this->geofenceService->isInsideZone($geofenceId, $latitude, $longitude);
    }

    /**
     * Append a ZONE_TRANSITION row to the immutable shift_events audit trail.
     * Best-effort — a logging failure must never block the ping.
     */
    private function logZoneTransitionEvent(
        string $guardId,
        string $shiftId,
        ?string $geofenceId,
        string $fromStatus,
        string $toStatus,
        float $latitude,
        float $longitude,
        Carbon $now
    ): void {
        try {
            DB::table('shift_events')->insert([
                'id' => (string) Str::uuid(),
                'shift_id' => $shiftId,
                'guard_id' => $guardId,
                'event_type' => 'ZONE_TRANSITION',
                'metadata' => json_encode([
                    'geofence_id' => $geofenceId,
                    'from_status' => $fromStatus,
                    'to_status' => $toStatus,
                    'coordinates' => [
                        'latitude' => $latitude,
                        'longitude' => $longitude,
                    ],
                ]),
                'recorded_at' => $now,
                'server_received_at' => $now,
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to log zone transition', [
                'guard_id' => $guardId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a reconnect on the immutable shift_events trail: a COMMS_GAP_START at
     * the last server-receipt, a COMMS_GAP_END at reconnect, and a SYNC_FLUSH
     * summary of what this GPS flush carried. The Phase 7 dashboard renders the
     * START/END pair as an "offline / backfilled" band (D-04). Best-effort — an
     * audit failure must never block the ping flush.
     *
     * NOTE: the SYNC_FLUSH summary counts only the GPS pings in this batch.
     * Wakefulness/photo replays drain through their own endpoints and stay
     * individually audited (WAKEFULNESS_* / PHOTO_*). A single cross-capability
     * receipt would need a dedicated app-called "sync complete" endpoint — a
     * future Flutter-contract item, out of scope here.
     */
    private function logCommsGap(
        string $guardId,
        string $shiftId,
        Carbon $gapStart,
        Carbon $reconnectedAt,
        int $gapSeconds,
        int $pingsApplied
    ): void {
        try {
            $rows = [
                [
                    'event_type' => 'COMMS_GAP_START',
                    'recorded_at' => $gapStart,
                    'metadata' => [
                        'detected_on_reconnect' => true,
                        'last_seen_at' => $gapStart->toISOString(),
                    ],
                ],
                [
                    'event_type' => 'COMMS_GAP_END',
                    'recorded_at' => $reconnectedAt,
                    'metadata' => [
                        'gap_seconds' => $gapSeconds,
                        'reconnected_at' => $reconnectedAt->toISOString(),
                    ],
                ],
                [
                    'event_type' => 'SYNC_FLUSH',
                    'recorded_at' => $reconnectedAt,
                    'metadata' => [
                        'gap_seconds' => $gapSeconds,
                        'gps_pings_synced' => $pingsApplied,
                        'note' => 'GPS backlog flushed on reconnect; wakefulness/photo replays audited separately.',
                    ],
                ],
            ];

            foreach ($rows as $row) {
                DB::table('shift_events')->insert([
                    'id' => (string) Str::uuid(),
                    'shift_id' => $shiftId,
                    'guard_id' => $guardId,
                    'event_type' => $row['event_type'],
                    'metadata' => json_encode($row['metadata']),
                    'recorded_at' => $row['recorded_at'],
                    'server_received_at' => $reconnectedAt,
                    'created_at' => $reconnectedAt,
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed to log comms gap', [
                'guard_id' => $guardId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
