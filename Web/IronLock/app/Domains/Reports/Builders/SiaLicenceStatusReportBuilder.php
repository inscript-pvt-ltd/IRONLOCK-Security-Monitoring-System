<?php

namespace App\Domains\Reports\Builders;

use App\Domains\Guards\Models\Guard;
use App\Domains\Reports\Support\ReportType;
use Carbon\Carbon;

/**
 * SIA Licence Status (REP-004) — the licence roster for every active guard:
 * licence number, type, expiry, and a status flag (VALID / EXPIRING within 30
 * days / EXPIRED). This is the deployability check a supervisor runs before
 * rostering — an expired SIA licence means the guard cannot legally work.
 *
 * Available as PDF (a sign-off sheet) and CSV (for HR/compliance tracking).
 */
class SiaLicenceStatusReportBuilder extends ReportBuilder
{
    /** Days-before-expiry that counts as "expiring soon" (mirrors Guard helper). */
    private const EXPIRING_DAYS = 30;

    public function type(): ReportType
    {
        return ReportType::SiaLicenceStatus;
    }

    public function build(array $params): array
    {
        $now = Carbon::now();
        $soon = $now->copy()->addDays(self::EXPIRING_DAYS);

        $guards = Guard::where('employment_status', 'active')
            ->orderBy('sia_licence_expiry')
            ->get();

        $rows = $guards->map(function (Guard $g) use ($now, $soon) {
            $expiry = $g->sia_licence_expiry;

            $status = 'VALID';
            if ($expiry === null) {
                $status = 'UNKNOWN';
            } elseif ($expiry->lessThan($now)) {
                $status = 'EXPIRED';
            } elseif ($expiry->lessThanOrEqualTo($soon)) {
                $status = 'EXPIRING';
            }

            return [
                'employee_code' => $g->employee_code,
                'name' => trim($g->first_name . ' ' . $g->last_name),
                'licence_number' => $g->sia_licence_number ?? '—',
                'licence_type' => $g->sia_licence_type ?? '—',
                'expiry' => optional($expiry)->toDateString() ?? '—',
                'days_remaining' => $expiry ? (int) round($now->diffInDays($expiry, false)) : null,
                'status' => $status,
            ];
        })->all();

        $counts = [
            'total' => count($rows),
            'valid' => count(array_filter($rows, fn ($r) => $r['status'] === 'VALID')),
            'expiring' => count(array_filter($rows, fn ($r) => $r['status'] === 'EXPIRING')),
            'expired' => count(array_filter($rows, fn ($r) => $r['status'] === 'EXPIRED')),
        ];

        return [
            'title' => $this->type()->label(),
            'view' => 'reports.pdf.sia_licence_status',
            'view_data' => [
                'title' => $this->type()->label(),
                'generated_on' => $now->toDateString(),
                'rows' => $rows,
                'counts' => $counts,
            ],
            'csv' => [
                'headers' => ['Employee Code', 'Name', 'Licence Number', 'Licence Type', 'Expiry', 'Days Remaining', 'Status'],
                'rows' => array_map(fn ($r) => [
                    $r['employee_code'], $r['name'], $r['licence_number'], $r['licence_type'],
                    $r['expiry'], $r['days_remaining'], $r['status'],
                ], $rows),
            ],
            'shift_id' => null,
            'slug' => 'sia-licence-status-' . $now->format('Ymd'),
        ];
    }
}
