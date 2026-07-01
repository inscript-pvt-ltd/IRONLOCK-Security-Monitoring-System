<?php

namespace App\Domains\Reports\Builders;

use App\Domains\Guards\Models\Guard;
use App\Domains\Reports\Exceptions\ReportGenerationException;
use App\Domains\Reports\Support\ReportType;
use App\Domains\Shifts\Models\Shift;
use Carbon\Carbon;

/**
 * Working Time Compliance (REP-005) — the Working Time Regulations 1998 check
 * for one guard over the standard 17-week reference period: total hours worked,
 * the average weekly working time, and whether it breaches the 48-hour average
 * limit. Lists the contributing shifts so the figure is auditable.
 *
 * Hours are taken from each completed shift's actual worked duration (the
 * immutable compliance_summary snapshot where present, else the live actual
 * duration); a still-running or scheduled shift contributes its scheduled hours
 * so an in-progress period is not understated.
 *
 * Available as PDF and CSV.
 */
class WtrComplianceReportBuilder extends ReportBuilder
{
    /** The WTR reference period and the average weekly limit. */
    private const REFERENCE_WEEKS = 17;
    private const WEEKLY_LIMIT_HOURS = 48.0;

    public function type(): ReportType
    {
        return ReportType::WtrCompliance;
    }

    public function build(array $params): array
    {
        $guard = Guard::find($params['guard_id'] ?? null);

        if (!$guard) {
            throw new ReportGenerationException('A guard must be selected for the Working Time Compliance report.');
        }

        // 17-week window ending at the reference date (default: now).
        $end = !empty($params['reference_date'])
            ? Carbon::parse($params['reference_date'])->endOfDay()
            : Carbon::now();
        $start = $end->copy()->subWeeks(self::REFERENCE_WEEKS)->startOfDay();

        $shifts = Shift::where('guard_id', $guard->id)
            ->where('status', '!=', Shift::STATUS_CANCELLED)
            ->whereBetween('scheduled_start', [$start, $end])
            ->orderBy('scheduled_start')
            ->get();

        $rows = [];
        $totalHours = 0.0;

        foreach ($shifts as $shift) {
            $hours = $this->workedHours($shift);
            $totalHours += $hours;

            $rows[] = [
                'reference' => $shift->reference ?? '—',
                'date' => optional($shift->scheduled_start)->toDateString(),
                'status' => $shift->status,
                'hours' => round($hours, 2),
            ];
        }

        $averageWeekly = round($totalHours / self::REFERENCE_WEEKS, 2);
        $compliant = $averageWeekly <= self::WEEKLY_LIMIT_HOURS;

        return [
            'title' => $this->type()->label(),
            'view' => 'reports.pdf.wtr_compliance',
            'view_data' => [
                'title' => $this->type()->label(),
                'guard_name' => trim($guard->first_name . ' ' . $guard->last_name),
                'employee_code' => $guard->employee_code,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'reference_weeks' => self::REFERENCE_WEEKS,
                'weekly_limit' => self::WEEKLY_LIMIT_HOURS,
                'total_hours' => round($totalHours, 2),
                'average_weekly' => $averageWeekly,
                'compliant' => $compliant,
                'rows' => $rows,
            ],
            'csv' => [
                'headers' => ['Shift', 'Date', 'Status', 'Hours'],
                'rows' => array_map(fn ($r) => [$r['reference'], $r['date'], $r['status'], $r['hours']], $rows),
            ],
            'shift_id' => null,
            'slug' => 'wtr-compliance-' . $this->slugify($guard->employee_code ?? $guard->id) . '-' . $end->format('Ymd'),
        ];
    }

    /**
     * Worked hours credited for a shift: the actual duration for a completed
     * shift (snapshot-preferred), else the scheduled duration so an in-progress
     * or upcoming shift in the window still counts toward the average.
     */
    private function workedHours(Shift $shift): float
    {
        if ($shift->status === Shift::STATUS_COMPLETED) {
            $summary = is_array($shift->compliance_summary) ? $shift->compliance_summary : null;
            $actual = $summary['shift_duration']['actual_hours'] ?? $shift->actual_duration;

            return (float) ($actual ?? $shift->scheduled_duration ?? 0);
        }

        return (float) ($shift->scheduled_duration ?? 0);
    }
}
