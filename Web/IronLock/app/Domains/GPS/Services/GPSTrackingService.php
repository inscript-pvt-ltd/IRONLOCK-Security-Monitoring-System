<?php

namespace App\Domains\GPS\Services;

use App\Domains\Alerts\Services\AlertService;
use App\Domains\Geofences\Services\GeofenceService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Shifts\Models\Shift;
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
 * ST_CONTAINS check via GeofenceService to set zone_status. On an INSIDE→OUTSIDE
 * transition we log a ZONE_TRANSITION shift_event and schedule a grace-period
 * CheckZoneExitJob; the job — not this method — decides whether to raise a
 * ZONE_EXIT alert. The client clock is never trusted: `recorded_at` is kept for
 * diagnostics only and `updated_at` (the server's authoritative "last seen") is
 * what the dashboard and COMMS_INTERRUPTED logic rely on.
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
     * $dispatchZoneCheck gates the *live* grace-period zone-exit job. It is true
     * for a normal standalone ping (so a fresh INSIDE→OUTSIDE edge schedules the
     * check inline), and false when the caller is replaying a flush — there, the
     * decision is deferred to finalizeFlush() so a buffered backlog logs its
     * history without paging retroactively for events already over.
     */
    public function recordLocation(
        string $guardId,
        string $shiftId,
        array $locationData,
        bool $dispatchZoneCheck = true
    ): GuardLocation {
        $now = Carbon::now();

        // Read the prior zone status before the UPSERT overwrites the row, so
        // we can detect a transition. guard_locations is a single mutable row
        // per guard (no history), so a row left over from a PREVIOUS shift must
        // never be read as this shift's prior state — that would log a phantom
        // cross-shift ZONE_TRANSITION on the first ping. Scope by shift_id, and
        // when there is no same-shift prior fall back to the shift-start
        // baseline of INSIDE_ZONE (the guard is expected at their post), so a
        // guard who is already OUTSIDE on their first ping registers a real
        // INSIDE→OUTSIDE transition instead of being silently missed.
        $previous = GuardLocation::where('guard_id', $guardId)->first();
        $previousZoneStatus = ($previous && $previous->shift_id === $shiftId)
            ? $previous->zone_status
            : GeofenceService::STATUS_INSIDE_ZONE;

        // Resolve zone status via the shift's geofence (server-side spatial check).
        $shift = Shift::with('site')->find($shiftId);
        $geofenceId = $shift?->geofence_id;
        $zoneStatus = GeofenceService::STATUS_INSIDE_ZONE;

        if ($geofenceId) {
            $isInside = $this->geofenceService->isInsideZone(
                $geofenceId,
                (float) $locationData['latitude'],
                (float) $locationData['longitude']
            );

            $zoneStatus = $isInside
                ? GeofenceService::STATUS_INSIDE_ZONE
                : GeofenceService::STATUS_OUTSIDE_ZONE;
        }

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
                'zone_status' => $zoneStatus,
                'recorded_at' => isset($locationData['recorded_at'])
                    ? Carbon::parse($locationData['recorded_at'])
                    : null,
                'updated_at' => Carbon::now(),
            ]
        );

        // Only act when the zone status actually changes (avoids per-ping noise).
        // The ZONE_TRANSITION audit row is ALWAYS written so the timeline is
        // complete even for a replayed backlog; only the *live alert* dispatch is
        // gated by $dispatchZoneCheck.
        if ($previousZoneStatus !== null && $previousZoneStatus !== $zoneStatus) {
            $this->logZoneTransitionEvent(
                $guardId,
                $shiftId,
                $geofenceId,
                $previousZoneStatus,
                $zoneStatus,
                (float) $locationData['latitude'],
                (float) $locationData['longitude'],
                $now
            );

            // On leaving the zone, schedule a grace-period check. The job
            // re-confirms the guard is still outside before raising an alert.
            // Suppressed during a flush replay — finalizeFlush() decides once.
            if ($dispatchZoneCheck && $zoneStatus === GeofenceService::STATUS_OUTSIDE_ZONE) {
                $gracePeriodMinutes = $shift?->site?->grace_period_minutes ?? 5;

                CheckZoneExitJob::dispatch($guardId, $shiftId, $now->toISOString())
                    ->delay(now()->addMinutes($gracePeriodMinutes));
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
     */
    public function finalizeFlush(string $guardId, string $shiftId, array $pre, int $pingsApplied): void
    {
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

        if ($current
            && $current->zone_status === GeofenceService::STATUS_OUTSIDE_ZONE
            && $preZoneStatus === GeofenceService::STATUS_INSIDE_ZONE) {
            $shift = Shift::with('site')->find($shiftId);
            $gracePeriodMinutes = $shift?->site?->grace_period_minutes ?? 5;

            CheckZoneExitJob::dispatch($guardId, $shiftId, $now->toISOString())
                ->delay(now()->addMinutes($gracePeriodMinutes));
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
