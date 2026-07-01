<?php

namespace App\Domains\Reports\Builders;

use App\Domains\Alerts\Models\Alert;
use App\Domains\Reports\Exceptions\ReportGenerationException;
use App\Domains\Reports\Support\ReportType;
use Carbon\Carbon;

/**
 * Alert History — every supervisor alert raised in a date range (optionally
 * narrowed to one guard or site), with its type, severity, raised/acknowledged
 * times and the acting supervisor. Available as PDF (a readable incident log)
 * and CSV (for spreadsheet analysis of alert volume/response).
 */
class AlertHistoryReportBuilder extends ReportBuilder
{
    public function type(): ReportType
    {
        return ReportType::AlertHistory;
    }

    public function build(array $params): array
    {
        [$from, $to] = $this->resolveRange($params);

        $query = Alert::with(['assignedGuard', 'shift.site'])
            ->whereBetween('raised_at', [$from, $to])
            ->orderByDesc('raised_at');

        if (!empty($params['guard_id'])) {
            $query->where('guard_id', $params['guard_id']);
        }
        if (!empty($params['site_id'])) {
            // Alerts carry no site_id; narrow via the related shift's site.
            $siteId = (string) $params['site_id'];
            $query->whereHas('shift', fn ($q) => $q->where('site_id', $siteId));
        }

        $alerts = $query->get();

        // ISO-8601 UTC so both the PDF and the CSV can localise to the viewer's
        // timezone at download (TIMESTAMP_HANDLING.md).
        $iso = fn ($dt) => $dt ? Carbon::parse($dt)->utc()->format('Y-m-d\TH:i:s\Z') : null;

        $rows = $alerts->map(function (Alert $a) use ($iso) {
            $guard = $a->assignedGuard;

            return [
                'raised_at' => $iso($a->raised_at),
                'type' => $a->type,
                'severity' => $a->severity,
                'status' => $a->status,
                'guard' => $guard ? trim($guard->first_name . ' ' . $guard->last_name) : '—',
                'site' => $a->shift->site->name ?? '—',
                'title' => $a->title,
                'description' => $a->description,
                'acknowledged_at' => $iso($a->acknowledged_at),
            ];
        })->all();

        $counts = [
            'total' => count($rows),
            'critical' => $alerts->where('severity', 'CRITICAL')->count(),
            'warning' => $alerts->where('severity', 'WARNING')->count(),
            'open' => $alerts->where('status', 'OPEN')->count(),
        ];

        return [
            'title' => $this->type()->label(),
            'view' => 'reports.pdf.alert_history',
            'view_data' => [
                'title' => $this->type()->label(),
                'range_label' => $from->toDateString() . ' → ' . $to->toDateString(),
                'rows' => $rows,
                'counts' => $counts,
            ],
            'csv' => [
                'headers' => ['Raised At', 'Type', 'Severity', 'Status', 'Guard', 'Site', 'Title', 'Description', 'Acknowledged At'],
                'rows' => array_map(fn ($r) => [
                    $r['raised_at'], $r['type'], $r['severity'], $r['status'],
                    $r['guard'], $r['site'], $r['title'], $r['description'], $r['acknowledged_at'],
                ], $rows),
            ],
            'shift_id' => null,
            'slug' => 'alert-history-' . $from->format('Ymd') . '-' . $to->format('Ymd'),
        ];
    }

    /**
     * @return array{0:Carbon,1:Carbon}
     */
    private function resolveRange(array $params): array
    {
        if (empty($params['date_from']) || empty($params['date_to'])) {
            throw new ReportGenerationException('A date range is required for this report.');
        }

        $from = Carbon::parse($params['date_from'])->startOfDay();
        $to = Carbon::parse($params['date_to'])->endOfDay();

        if ($to->lessThan($from)) {
            throw new ReportGenerationException('The end date must be on or after the start date.');
        }

        return [$from, $to];
    }
}
