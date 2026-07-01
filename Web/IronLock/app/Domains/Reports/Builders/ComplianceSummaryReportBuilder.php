<?php

namespace App\Domains\Reports\Builders;

use App\Domains\Compliance\Services\ComplianceCalculator;
use App\Domains\Reports\Exceptions\ReportGenerationException;
use App\Domains\Reports\Support\ReportType;
use App\Domains\Shifts\Models\Shift;
use Carbon\Carbon;

/**
 * Compliance Summary (REP-001) — the compliance overview for either a single
 * shift or a date range (optionally narrowed to one guard or site).
 *
 * For a range it lists every completed shift in the window with its per-shift
 * compliance (WTR duration/violations, attendance, GPS coverage, wakefulness &
 * photo tallies) and totals them, giving supervisors a portfolio-level view of
 * how well cover was actually delivered.
 */
class ComplianceSummaryReportBuilder extends ReportBuilder
{
    public function __construct(private readonly ComplianceCalculator $compliance) {}

    public function type(): ReportType
    {
        return ReportType::ComplianceSummary;
    }

    public function build(array $params): array
    {
        // Single-shift mode when a shift_id is supplied; otherwise date-range.
        if (!empty($params['shift_id'])) {
            return $this->buildForShift((string) $params['shift_id']);
        }

        return $this->buildForRange($params);
    }

    private function buildForShift(string $shiftId): array
    {
        $shift = Shift::with(['assignedGuard', 'site'])->find($shiftId);

        if (!$shift) {
            throw new ReportGenerationException('The shift for this report could not be found.');
        }

        $rows = [$this->rowFor($shift)];

        return $this->payload(
            scope: 'Shift ' . ($shift->reference ?? $shift->id),
            rangeLabel: null,
            rows: $rows,
            slug: 'compliance-summary-' . $this->slugify($shift->reference ?? $shift->id),
            shiftId: $shift->id,
        );
    }

    private function buildForRange(array $params): array
    {
        [$from, $to] = $this->resolveRange($params);

        $query = Shift::with(['assignedGuard', 'site'])
            ->where('status', Shift::STATUS_COMPLETED)
            ->whereBetween('scheduled_start', [$from, $to])
            ->orderBy('scheduled_start');

        if (!empty($params['guard_id'])) {
            $query->where('guard_id', $params['guard_id']);
        }
        if (!empty($params['site_id'])) {
            $query->where('site_id', $params['site_id']);
        }

        $rows = $query->get()->map(fn (Shift $s) => $this->rowFor($s))->all();

        return $this->payload(
            scope: 'Date range',
            rangeLabel: $from->toDateString() . ' → ' . $to->toDateString(),
            rows: $rows,
            slug: 'compliance-summary-' . $from->format('Ymd') . '-' . $to->format('Ymd'),
            shiftId: null,
        );
    }

    /**
     * One shift's compliance row for the summary table.
     */
    private function rowFor(Shift $shift): array
    {
        $c = $this->compliance->forShift($shift);
        $guard = $shift->assignedGuard;

        return [
            'reference' => $shift->reference ?? '—',
            'guard' => $guard ? trim($guard->first_name . ' ' . $guard->last_name) : '—',
            'site' => $shift->site->name ?? '—',
            'date' => optional($shift->scheduled_start)->toDateString(),
            'actual_hours' => $c['wtr']['actual_hours'],
            'wtr_violations' => count($c['wtr']['violations'] ?? []),
            'started_on_time' => $c['wtr']['started_on_time'],
            'ended_on_time' => $c['wtr']['ended_on_time'],
            'gps_coverage' => $c['gps']['coverage_percent'],
            'wakefulness' => $c['wakefulness'],
            'photos' => $c['photos'],
        ];
    }

    private function payload(string $scope, ?string $rangeLabel, array $rows, string $slug, ?string $shiftId): array
    {
        // Portfolio totals across the listed shifts.
        $totals = [
            'shifts' => count($rows),
            'wtr_violations' => array_sum(array_column($rows, 'wtr_violations')),
            'wakefulness_confirmed' => array_sum(array_map(fn ($r) => $r['wakefulness']['confirmed'], $rows)),
            'wakefulness_total' => array_sum(array_map(fn ($r) => $r['wakefulness']['total'], $rows)),
            'photos_approved' => array_sum(array_map(fn ($r) => $r['photos']['approved'], $rows)),
            'photos_images' => array_sum(array_map(fn ($r) => $r['photos']['images'], $rows)),
        ];

        $yn = fn ($v) => $v === null ? '—' : ($v ? 'Yes' : 'No');

        return [
            'title' => $this->type()->label(),
            'view' => 'reports.pdf.compliance_summary',
            'view_data' => [
                'title' => $this->type()->label(),
                'scope' => $scope,
                'range_label' => $rangeLabel,
                'rows' => $rows,
                'totals' => $totals,
            ],
            'csv' => [
                'headers' => ['Shift', 'Date', 'Guard', 'Site', 'Actual Hours', 'WTR Violations', 'Started On Time', 'Ended On Time', 'GPS Coverage %', 'Wakefulness Confirmed', 'Wakefulness Total', 'Photos Approved', 'Photos Images'],
                'rows' => array_map(fn ($r) => [
                    $r['reference'], $r['date'], $r['guard'], $r['site'],
                    $r['actual_hours'] !== null ? round((float) $r['actual_hours'], 2) : '',
                    $r['wtr_violations'], $yn($r['started_on_time']), $yn($r['ended_on_time']),
                    $r['gps_coverage'] !== null ? round((float) $r['gps_coverage'], 1) : '',
                    $r['wakefulness']['confirmed'], $r['wakefulness']['total'],
                    $r['photos']['approved'], $r['photos']['images'],
                ], $rows),
            ],
            'shift_id' => $shiftId,
            'slug' => $slug,
        ];
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveRange(array $params): array
    {
        if (empty($params['date_from']) || empty($params['date_to'])) {
            throw new ReportGenerationException('A shift or a date range is required for this report.');
        }

        $from = Carbon::parse($params['date_from'])->startOfDay();
        $to = Carbon::parse($params['date_to'])->endOfDay();

        if ($to->lessThan($from)) {
            throw new ReportGenerationException('The end date must be on or after the start date.');
        }

        return [$from, $to];
    }
}
