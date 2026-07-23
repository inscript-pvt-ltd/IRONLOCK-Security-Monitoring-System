<?php

namespace App\Domains\Reports\Builders;

use App\Domains\Reports\Exceptions\ReportGenerationException;
use App\Domains\Reports\Support\ReportType;
use App\Domains\Reports\Support\ShiftReportComposer;
use App\Domains\Shifts\Models\Shift;

/**
 * Shift Welfare Report (REP-002) — the full per-shift welfare/verification
 * record for one shift. This is IronLock's flagship report: it renders as the
 * rich on-screen "Shift Report" page (the wf- design) AND a PDF, both from the
 * same frozen snapshot assembled by ShiftReportComposer.
 *
 * The builder itself is thin — all section-gathering lives in the composer so
 * the exact same data drives the HTML page, the PDF and the CSV. Its view_data
 * is pure arrays (no Eloquent models) so it can be stored verbatim as the
 * report's frozen snapshot.
 */
class ShiftWelfareReportBuilder extends ReportBuilder
{
    public function __construct(private readonly ShiftReportComposer $composer) {}

    public function type(): ReportType
    {
        return ReportType::ShiftWelfare;
    }

    public function build(array $params): array
    {
        $shift = Shift::with(['assignedGuard', 'site', 'geofence', 'creator'])
            ->find($params['shift_id'] ?? null);

        if (!$shift) {
            throw new ReportGenerationException('The shift for this report could not be found.');
        }

        $sections = $this->composer->compose($shift, $params);

        return [
            'title' => $this->type()->label(),
            'view' => 'reports.pdf.shift_welfare',
            'view_data' => array_merge($sections, [
                'title' => $this->type()->label(),
                'doc_tag' => 'SHIFT WELFARE REPORT',
                'subtitle' => 'Compliance & evidence record for a single completed security shift',
            ]),
            'csv' => $this->csvFor($sections),
            'shift_id' => $shift->id,
            'slug' => 'shift-welfare-' . $this->slugify($shift->reference ?? $shift->id),
        ];
    }

    /**
     * A flat, spreadsheet-friendly export of the whole welfare snapshot.
     *
     * Every row is [Section, Field, Value, Time]. Scalar fields go in Value with
     * an empty Time; every timestamp is placed alone in the Time column (as an
     * ISO-UTC string) so it — and only it — is localised to the viewer's
     * timezone at download. Nothing is fabricated: missing values stay '—'.
     *
     * @param  array<string,mixed>  $s  the composed sections
     * @return array{headers:list<string>,rows:list<array>}
     */
    private function csvFor(array $s): array
    {
        $rows = [];
        $add = function (string $section, string $field, $value = '', ?string $time = null) use (&$rows) {
            $rows[] = [$section, $field, $value === null ? '—' : $value, $time ?? ''];
        };

        $info = $s['shift_info'] ?? [];
        $add('Shift', 'Guard', $info['guard_name'] ?? '—');
        $add('Shift', 'Employee ID', $info['employee_code'] ?? '—');
        $add('Shift', 'Site', $info['site_name'] ?? '—');
        $add('Shift', 'Reference', $info['shift_reference'] ?? '—');
        $add('Shift', 'Supervisor', $info['supervisor'] ?? '—');
        $add('Shift', 'Status', $info['status_label'] ?? '—');
        $add('Shift', 'Scheduled Start', '', $info['scheduled_start'] ?? null);
        $add('Shift', 'Scheduled End', '', $info['scheduled_end'] ?? null);
        $add('Shift', 'Actual Start', '', $info['actual_start'] ?? null);
        $add('Shift', 'Actual End', '', $info['actual_end'] ?? null);
        $add('Shift', 'End Type', $info['end_type_label'] ?? 'Guard ended');
        $add('Shift', 'Duration', $info['duration_label'] ?? '—');

        $att = $s['attendance'] ?? [];
        $add('Attendance', 'Check-In', $att['checkin_status'] ?? '—');
        $add('Attendance', 'Check-Out', $att['checkout_status'] ?? '—');
        $add('Attendance', 'Late Arrival', $att['late_arrival'] ?? '—');
        $add('Attendance', 'Early Leave', $att['early_leave'] ?? '—');
        $add('Attendance', 'Total Hours', $att['total_hours'] ?? '—');
        $add('Attendance', 'WTR Compliance', $att['wtr_compliance'] ?? '—');
        $add('Attendance', 'Override Used', $att['override_used'] ?? '—');

        $gps = $s['gps'] ?? [];
        $cov = $gps['coverage_percent'] ?? null;
        $add('GPS', 'Coverage %', $cov === null ? '—' : round((float) $cov, 1));
        $add('GPS', 'Time Inside Geofence', $gps['time_inside_label'] ?? '—');
        $add('GPS', 'Time Outside Zone', $gps['time_outside_label'] ?? '—');
        $add('GPS', 'Time Offline', $gps['time_offline_label'] ?? '—');
        $add('GPS', 'Zone Exits', $gps['exit_count'] ?? 0);
        $add('GPS', 'Gap Count', $gps['gap_count'] ?? 0);
        $add('GPS', 'Final Location', $gps['final_location'] ?? '—');

        $wtr = $s['wtr'] ?? [];
        $add('WTR', 'Total Hours', $wtr['total_hours'] ?? '—');
        $add('WTR', 'Scheduled Hours', $wtr['scheduled_hours'] ?? '—');
        $add('WTR', 'Status', $wtr['status'] ?? '—');

        foreach ($s['wakefulness'] ?? [] as $i => $c) {
            $label = 'Check ' . ($i + 1) . ' (' . ($c['mode'] ?? '—') . ')';
            $value = ($c['result'] ?? '—') . (!empty($c['failure_reason']) ? ' — ' . $c['failure_reason'] : '');
            $add('Wakefulness', $label, $value, $c['time'] ?? null);
        }

        foreach ($s['photos'] ?? [] as $p) {
            $no = $p['request_no'] ?? '?';
            $add('Photo', 'Request ' . $no . ' requested (' . ($p['mode'] ?? '—') . ')', ucfirst((string) ($p['status'] ?? '—')), $p['requested_at'] ?? null);
            if (!empty($p['submitted_at'])) {
                $add('Photo', 'Request ' . $no . ' submitted', '', $p['submitted_at']);
            }
            // Liveness proof + the raw SHA-256 fingerprints stay in the CSV (the
            // machine-readable/forensic copy) even though the report page shows
            // only the plain-language result. Both are toggle-gated by the composer.
            if (!empty($p['liveness'])) {
                $add('Photo', 'Request ' . $no . ' liveness proof', $p['liveness']);
            }
            foreach ($p['images'] ?? [] as $img) {
                if (!empty($img['sha256'])) {
                    $label = 'Request ' . $no . ' image hash' . (!empty($img['name']) ? ' (' . $img['name'] . ')' : '');
                    $add('Photo', $label, $img['sha256']);
                }
            }
        }

        foreach ($s['alerts'] ?? [] as $a) {
            $add('Alert', ($a['name'] ?? '—') . ' (' . ($a['severity'] ?? '—') . ')', ucfirst(strtolower((string) ($a['status'] ?? '—'))), $a['trigger_time'] ?? null);
        }

        foreach ($s['offline_sync'] ?? [] as $i => $g) {
            $meta = ($g['offline_duration'] ?? '—') . ' offline · ' . ($g['gps_backfilled'] ?? 0) . ' GPS pings';
            $add('Offline Sync', 'Gap ' . ($i + 1) . ' start (' . $meta . ')', '', $g['gap_start'] ?? null);
            $add('Offline Sync', 'Gap ' . ($i + 1) . ' end', '', $g['gap_end'] ?? null);
        }

        foreach ($s['timeline'] ?? [] as $e) {
            $add('Timeline', $e['type'] ?? '—', $e['desc'] ?? '', $e['time'] ?? null);
        }

        foreach ($s['admin_actions'] ?? [] as $a) {
            $add('Admin Action', $a['action'] ?? '—', $a['admin'] ?? '—', $a['timestamp'] ?? null);
        }

        return [
            'headers' => ['Section', 'Field', 'Value', 'Time'],
            'rows' => $rows,
        ];
    }
}
