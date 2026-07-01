<?php

namespace App\Domains\Compliance\Services;

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

    /**
     * GPS coverage over the shift's active window: the proportion of the worked
     * period during which the guard's position was being received.
     *
     * There is no per-ping history table (guard_locations holds one mutable
     * live row), so coverage is derived from the append-only comms-gap audit:
     * every reconnect after a backfill-threshold silence writes a COMMS_GAP_END
     * carrying gap_seconds. Coverage = (active_seconds − Σ gap_seconds) /
     * active_seconds. This mirrors the Phase 7 "no retroactive alerts" model —
     * a closed, backfilled gap is offline time, not covered time.
     *
     * The active window runs actual_start → actual_end (or scheduled_end, or
     * now for a still-active shift). Returns null percentages when the shift
     * never started (nothing to cover).
     *
     * @return array{active_seconds:int,gap_seconds:int,covered_seconds:int,coverage_percent:?float,gap_count:int}
     */
    public function gpsCoverage(Shift $shift): array
    {
        $start = $shift->actual_start;

        if (!$start) {
            return [
                'active_seconds' => 0,
                'gap_seconds' => 0,
                'covered_seconds' => 0,
                'coverage_percent' => null,
                'gap_count' => 0,
            ];
        }

        $end = $shift->actual_end ?? $shift->scheduled_end ?? Carbon::now();
        if ($end->lessThan($start)) {
            $end = $start;
        }

        $activeSeconds = (int) $start->diffInSeconds($end);

        // Sum the durations of comms gaps that closed during this shift. The
        // duration lives on the COMMS_GAP_END metadata (gap_seconds).
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
        $coveredSeconds = max(0, $activeSeconds - $gapSeconds);

        $coveragePercent = $activeSeconds > 0
            ? round($coveredSeconds / $activeSeconds * 100, 1)
            : null;

        return [
            'active_seconds' => $activeSeconds,
            'gap_seconds' => $gapSeconds,
            'covered_seconds' => $coveredSeconds,
            'coverage_percent' => $coveragePercent,
            'gap_count' => $gapEvents->count(),
        ];
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
