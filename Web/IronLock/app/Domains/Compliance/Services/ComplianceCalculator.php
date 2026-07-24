<?php

namespace App\Domains\Compliance\Services;

use App\Domains\Geofences\Services\GeofenceService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Photos\Models\PhotoReview;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use App\Domains\Wakefulness\Models\WakefulnessCheck;
use Carbon\Carbon;

/**
 * ComplianceCalculator — the single source of truth for a shift's compliance
 * tallies (Phase 8).
 *
 * The photo- and wakefulness-verification tallies were computed inline in
 * ShiftController@timeline; they are lifted here verbatim so the Shift Detail
 * side panel and the generated reports (REP-001/REP-002) read from ONE
 * implementation and can never drift apart. This service adds GPS coverage %,
 * which the timeline did not previously compute.
 *
 * Every value is server-sourced (shift_events.server_received_at,
 * shifts.compliance_summary) and read-only — the calculator never mutates the
 * append-only audit trail. All counts are computed live from the current DB
 * state (a review or a timeout sweep landing after shift-end is reflected
 * immediately), which is the exact behaviour the timeline relied on.
 */
class ComplianceCalculator
{
    /**
     * Photo-verification tally, image-level (a request may hold up to 5
     * images). Identical in shape and numbers to the timeline's $photoSummary.
     *
     * @return array{total:int,fulfilled:int,images:int,reviewed:int,approved:int,rejected:int,unreviewed:int}
     */
    public function photoSummary(Shift $shift): array
    {
        $requests = PhotoRequest::with('evidences.review')
            ->where('shift_id', $shift->id)
            ->get();

        $evidences = $requests->flatMap(fn (PhotoRequest $r) => $r->evidences);

        $total = $requests->count();
        $fulfilled = $requests->where('status', PhotoRequest::STATUS_FULFILLED)->count();
        $images = $evidences->count();
        $reviewed = $evidences->filter(fn ($e) => $e->review !== null)->count();
        $approved = $evidences->filter(fn ($e) => $e->review?->decision === PhotoReview::DECISION_APPROVED)->count();
        $rejected = $evidences->filter(fn ($e) => $e->review?->decision === PhotoReview::DECISION_REJECTED)->count();

        return [
            'total' => $total,
            'fulfilled' => $fulfilled,
            'images' => $images,
            'reviewed' => $reviewed,
            'approved' => $approved,
            'rejected' => $rejected,
            'unreviewed' => max(0, $images - $reviewed),
        ];
    }

    /**
     * Wakefulness-verification tally. Identical in shape and numbers to the
     * timeline's $wakefulnessSummary.
     *
     * @return array{total:int,confirmed:int,failed:int,pending:int}
     */
    public function wakefulnessSummary(Shift $shift): array
    {
        $checks = WakefulnessCheck::where('shift_id', $shift->id)->get();

        return [
            'total' => $checks->count(),
            'confirmed' => $checks->where('result', WakefulnessCheck::RESULT_CONFIRMED)->count(),
            'failed' => $checks->where('result', WakefulnessCheck::RESULT_FAILED)->count(),
            'pending' => $checks->whereNull('result')->count(),
        ];
    }

    /** Tolerance when matching a materialised check back to its schedule mark. */
    private const MISSED_MATCH_TOLERANCE_SECONDS = 90;

    /**
     * Scheduled verification marks that came due within the worked window but
     * were never fulfilled — no online request/challenge fired AND no offline
     * capture/challenge materialised on reconnect.
     *
     * These are "unverified" checks, distinct from a FAILED check (where the
     * guard answered wrong): the check simply never happened. The online
     * dispatcher deliberately skips an offline/unreachable guard (photos:
     * dispatch-scheduled / wakefulness:dispatch — the app's own offline capture
     * is meant to cover them), so a schedule mark with no trace at all means
     * neither path ran: the device was dark and nothing was flushed for it — a
     * real gap the supervisor should see.
     *
     * Computed on-read, never persisted: a late offline flush that materialises
     * a genuine check for the mark automatically stops it counting here —
     * self-healing and idempotent, with no synthetic audit rows to reconcile.
     *
     * @return array{photos: list<string>, wakefulness: list<string>}
     */
    public function missedScheduleMarks(Shift $shift): array
    {
        // Reconstruct the offline windows once — a mark only counts as missed if
        // we have positive proof the guard's device was dark at that moment.
        $windows = $this->offlineWindows($shift);

        // No recorded offline window ⇒ no offline miss is provable. A mark with no
        // check while the guard was ONLINE is a server/dispatch gap (e.g. a cron
        // lag), never the guard's fault, so it is deliberately NOT flagged here.
        if (empty($windows)) {
            return ['photos' => [], 'wakefulness' => []];
        }

        return [
            'photos' => $this->unfulfilledMarks(
                $shift,
                is_array($shift->photo_schedule) ? $shift->photo_schedule : [],
                PhotoRequest::where('shift_id', $shift->id)->pluck('requested_at')->all(),
                $windows
            ),
            'wakefulness' => $this->unfulfilledMarks(
                $shift,
                is_array($shift->wakefulness_schedule) ? $shift->wakefulness_schedule : [],
                WakefulnessCheck::where('shift_id', $shift->id)->pluck('scheduled_at')->all(),
                $windows
            ),
        ];
    }

    /**
     * The shift's recorded offline windows as [start, end] Carbon pairs, from the
     * COMMS_GAP_END audit events (each logged with its gap_seconds on reconnect;
     * start = reconnected_at − gap_seconds). A window is only ever written once
     * the guard reconnects and the on-device backlog has flushed, so a mark that
     * falls inside one has already had its chance to materialise a real check —
     * if none did, the check genuinely never happened.
     *
     * @return list<array{0:Carbon,1:Carbon}>
     */
    private function offlineWindows(Shift $shift): array
    {
        $ends = ShiftEvent::where('shift_id', $shift->id)
            ->where('event_type', 'COMMS_GAP_END')
            ->get();

        $windows = [];
        foreach ($ends as $event) {
            $endAt = $event->recorded_at instanceof Carbon
                ? $event->recorded_at->copy()
                : Carbon::parse($event->recorded_at);
            $gapSeconds = (int) round((float) ($event->metadata['gap_seconds'] ?? 0));
            if ($gapSeconds <= 0) {
                continue;
            }
            $windows[] = [$endAt->copy()->subSeconds($gapSeconds), $endAt];
        }

        return $windows;
    }

    /**
     * Schedule marks inside [actual_start, active-end − staleness] with no
     * materialised check within tolerance. The staleness cutoff (the
     * dispatcher's own catch-up threshold) keeps a just-due mark on a still-
     * active shift from being flagged before the guard has had a chance to
     * answer or the app to flush. A materialised check is matched to its mark
     * by proximity: offline captures re-anchor requested_at to the mark, and
     * wakefulness scheduled_at IS the mark, so a tolerance comfortably below the
     * inter-mark gap (30–45 min) pairs them without ever crossing marks.
     *
     * @param  array<int,string>              $schedule    ISO schedule marks
     * @param  array<int,mixed>               $actualTimes requested_at / scheduled_at of real checks
     * @param  list<array{0:Carbon,1:Carbon}> $windows     recorded offline windows
     * @return list<string>                                ISO marks that went unverified
     */
    private function unfulfilledMarks(Shift $shift, array $schedule, array $actualTimes, array $windows): array
    {
        $start = $shift->actual_start;
        if (!$start || empty($schedule) || empty($windows)) {
            return [];
        }
        $start = $start instanceof Carbon ? $start->copy() : Carbon::parse($start);

        $end = $shift->actual_end ?? $shift->scheduled_end ?? Carbon::now();
        $end = $end instanceof Carbon ? $end->copy() : Carbon::parse($end);
        if ($end->lessThan($start)) {
            $end = $start->copy();
        }

        $actual = [];
        foreach ($actualTimes as $t) {
            if (empty($t)) {
                continue;
            }
            try {
                $actual[] = $t instanceof Carbon ? $t : Carbon::parse($t);
            } catch (\Throwable $e) {
                // Skip an unparseable timestamp rather than fail the whole tally.
            }
        }

        $tol = self::MISSED_MATCH_TOLERANCE_SECONDS;
        $missed = [];
        foreach ($schedule as $markIso) {
            try {
                $mark = Carbon::parse($markIso);
            } catch (\Throwable $e) {
                continue;
            }
            // Must have come due inside the worked window...
            if ($mark->lessThan($start) || $mark->greaterThan($end)) {
                continue;
            }
            // ...AND while the guard's device was provably offline (inside a
            // recorded comms gap). A mark the guard was online for but the server
            // never fired is a dispatch gap, not a guard miss — excluded.
            if (!$this->markInAnyWindow($mark, $windows)) {
                continue;
            }

            $fulfilled = false;
            foreach ($actual as $a) {
                if (abs($a->diffInSeconds($mark)) <= $tol) {
                    $fulfilled = true;
                    break;
                }
            }
            if (!$fulfilled) {
                $missed[] = $mark->toISOString();
            }
        }

        return $missed;
    }

    /**
     * True if $mark falls within any [start, end] offline window (inclusive of a
     * small tolerance at each edge, so a mark right on the boundary of the gap it
     * belongs to still counts).
     *
     * @param  list<array{0:Carbon,1:Carbon}> $windows
     */
    private function markInAnyWindow(Carbon $mark, array $windows): bool
    {
        $tol = self::MISSED_MATCH_TOLERANCE_SECONDS;
        foreach ($windows as [$from, $to]) {
            if ($mark->greaterThanOrEqualTo($from->copy()->subSeconds($tol))
                && $mark->lessThanOrEqualTo($to->copy()->addSeconds($tol))) {
                return true;
            }
        }

        return false;
    }

    /**
     * GPS coverage over the shift's active window: the proportion of the worked
     * period during which the guard was confirmed ON-POST — i.e. inside the
     * geofence AND sending live GPS.
     *
     * There is no per-ping history table (guard_locations holds one mutable
     * live row), so coverage is reconstructed from two append-only audit
     * signals, both deducted from the active window:
     *   1. OFFLINE time — every reconnect after a backfill-threshold silence
     *      writes a COMMS_GAP_END carrying gap_seconds (Σ = offline seconds).
     *   2. OUTSIDE-ZONE time — each INSIDE→OUTSIDE / OUTSIDE→INSIDE pair of
     *      ZONE_TRANSITION events bounds an excursion outside the geofence
     *      (see outsideZoneSeconds()).
     * Coverage = (active − offline − outside) / active. This mirrors the
     * Phase 7 "no retroactive alerts" model — a closed gap is offline time, and
     * an excursion the guard already returned from is still off-post time — and
     * makes the figure reflect real on-post presence, not merely connectivity.
     *
     * The three parts are kept non-overlapping and summing to the active window:
     * offline is authoritative (no observation possible while dark) and outside
     * is capped to what remains, so inside + outside + offline = active exactly.
     *
     * The active window runs actual_start → actual_end (or scheduled_end, or
     * now for a still-active shift). Returns null percentages when the shift
     * never started (nothing to cover).
     *
     * @return array{active_seconds:int,gap_seconds:int,outside_seconds:int,covered_seconds:int,coverage_percent:?float,gap_count:int,exit_count:int}
     */
    public function gpsCoverage(Shift $shift): array
    {
        $start = $shift->actual_start;

        if (!$start) {
            return [
                'active_seconds' => 0,
                'gap_seconds' => 0,
                'outside_seconds' => 0,
                'covered_seconds' => 0,
                'coverage_percent' => null,
                'gap_count' => 0,
                'exit_count' => 0,
            ];
        }

        $end = $shift->actual_end ?? $shift->scheduled_end ?? Carbon::now();
        if ($end->lessThan($start)) {
            $end = $start;
        }

        $activeSeconds = (int) $start->diffInSeconds($end);

        // (1) Offline seconds — sum of comms gaps that closed during this shift.
        // The duration lives on the COMMS_GAP_END metadata (gap_seconds).
        $gapEvents = ShiftEvent::where('shift_id', $shift->id)
            ->where('event_type', 'COMMS_GAP_END')
            ->get();

        $gapSeconds = 0;
        foreach ($gapEvents as $event) {
            $gapSeconds += (int) round((float) ($event->metadata['gap_seconds'] ?? 0));
        }

        // A gap can never exceed the active window (clamp defends against a
        // gap that straddles the shift boundary).
        $gapSeconds = min($gapSeconds, $activeSeconds);

        // (2) Outside-zone seconds — reconstructed from the ZONE_TRANSITION
        // trail. Observable only while online, so these intervals do not overlap
        // the offline gaps; capping to the non-offline remainder keeps the
        // breakdown coherent even if a backfilled excursion nudges the two.
        [$outsideSeconds, $exitCount] = $this->outsideZoneSeconds($shift, $start, $end);
        $outsideSeconds = min($outsideSeconds, max(0, $activeSeconds - $gapSeconds));

        // Covered = confirmed on-post: online AND inside the geofence.
        $coveredSeconds = max(0, $activeSeconds - $gapSeconds - $outsideSeconds);

        $coveragePercent = $activeSeconds > 0
            ? round($coveredSeconds / $activeSeconds * 100, 1)
            : null;

        return [
            'active_seconds' => $activeSeconds,
            'gap_seconds' => $gapSeconds,
            'outside_seconds' => $outsideSeconds,
            'covered_seconds' => $coveredSeconds,
            'coverage_percent' => $coveragePercent,
            'gap_count' => $gapEvents->count(),
            'exit_count' => $exitCount,
        ];
    }

    /**
     * Total time the guard spent outside the geofence over [$start, $end],
     * reconstructed from the immutable ZONE_TRANSITION audit trail.
     *
     * Each INSIDE→OUTSIDE edge opens an excursion; the next OUTSIDE→INSIDE edge
     * closes it. An excursion still open at shift end is closed at $end (the
     * guard never came back on record). All transition timestamps are clamped to
     * the active window so a stray edge cannot over- or under-count, and the
     * events are read in server-time order (recorded_at) so pairing is stable.
     *
     * @return array{0:int,1:int}  [outsideSeconds, exitCount]
     */
    private function outsideZoneSeconds(Shift $shift, Carbon $start, Carbon $end): array
    {
        $transitions = ShiftEvent::where('shift_id', $shift->id)
            ->where('event_type', 'ZONE_TRANSITION')
            ->orderBy('recorded_at')
            ->orderBy('created_at')
            ->get();

        $outsideSeconds = 0;
        $exitCount = 0;
        $outsideSince = null;

        foreach ($transitions as $t) {
            $to = is_array($t->metadata) ? ($t->metadata['to_status'] ?? null) : null;

            $at = $t->recorded_at instanceof Carbon
                ? $t->recorded_at->copy()
                : Carbon::parse($t->recorded_at);

            // Clamp into the active window (order-preserving, so pairing holds).
            if ($at->lessThan($start)) {
                $at = $start->copy();
            } elseif ($at->greaterThan($end)) {
                $at = $end->copy();
            }

            if ($to === GeofenceService::STATUS_OUTSIDE_ZONE) {
                if ($outsideSince === null) {
                    $outsideSince = $at;
                    $exitCount++;
                }
            } elseif ($to === GeofenceService::STATUS_INSIDE_ZONE) {
                if ($outsideSince !== null) {
                    $outsideSeconds += (int) $outsideSince->diffInSeconds($at);
                    $outsideSince = null;
                }
            }
        }

        // Never returned on record → outside until the shift window closes.
        if ($outsideSince !== null) {
            $outsideSeconds += (int) $outsideSince->diffInSeconds($end);
        }

        return [$outsideSeconds, $exitCount];
    }

    /**
     * WTR / attendance snapshot for the shift. Prefers the immutable
     * compliance_summary captured at shift-end (the point-in-time evidentiary
     * record); falls back to a live computation for a shift that has not been
     * ended yet.
     *
     * @return array{scheduled_hours:?float,actual_hours:?float,violations:array,overrides_used:int,started_on_time:?bool,ended_on_time:?bool,generated_at:?string,source:string}
     */
    public function wtrSummary(Shift $shift): array
    {
        $summary = is_array($shift->compliance_summary) ? $shift->compliance_summary : null;

        if ($summary) {
            return [
                'scheduled_hours' => $summary['shift_duration']['scheduled_hours'] ?? null,
                'actual_hours' => $summary['shift_duration']['actual_hours'] ?? null,
                'violations' => $summary['wtr_compliance']['violations'] ?? [],
                'overrides_used' => (int) ($summary['wtr_compliance']['overrides_used'] ?? 0),
                'started_on_time' => $summary['attendance']['started_on_time'] ?? null,
                'ended_on_time' => $summary['attendance']['ended_on_time'] ?? null,
                'generated_at' => $summary['generated_at'] ?? null,
                'source' => 'snapshot',
            ];
        }

        // Live fallback (shift not yet ended) — reuse the model's own helpers so
        // the numbers match what generateComplianceSummary() would persist.
        return [
            'scheduled_hours' => $shift->scheduled_duration,
            'actual_hours' => $shift->actual_duration,
            'violations' => $shift->hasWTRViolations(),
            'overrides_used' => $shift->workingTimeOverrides()->count(),
            'started_on_time' => null,
            'ended_on_time' => null,
            'generated_at' => null,
            'source' => 'live',
        ];
    }

    /**
     * The full compliance picture for one shift — every tally a report needs,
     * assembled once. Report builders call this; the timeline uses the
     * individual methods so it can keep its view-specific detail collections.
     *
     * @return array<string,mixed>
     */
    public function forShift(Shift $shift): array
    {
        return [
            'wtr' => $this->wtrSummary($shift),
            'photos' => $this->photoSummary($shift),
            'wakefulness' => $this->wakefulnessSummary($shift),
            'gps' => $this->gpsCoverage($shift),
        ];
    }
}
