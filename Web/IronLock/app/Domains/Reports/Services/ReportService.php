<?php

namespace App\Domains\Reports\Services;

use App\Domains\Reports\Builders\AlertHistoryReportBuilder;
use App\Domains\Reports\Builders\ComplianceSummaryReportBuilder;
use App\Domains\Reports\Builders\ReportBuilder;
use App\Domains\Reports\Builders\ShiftAuditReportBuilder;
use App\Domains\Reports\Builders\ShiftWelfareReportBuilder;
use App\Domains\Reports\Builders\SiaLicenceStatusReportBuilder;
use App\Domains\Reports\Builders\WtrComplianceReportBuilder;
use App\Domains\Reports\Exceptions\ReportGenerationException;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Support\CsvWriter;
use App\Domains\Reports\Support\ReportType;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * ReportService — orchestrates report generation (Phase 8).
 *
 * Flow: resolve the builder for the type → build the server-sourced payload →
 * render it (Blade→dompdf for PDF, CsvWriter for CSV) → persist the file to the
 * private `reports` disk → insert a `reports` row → return it.
 *
 * The file is stored under reports/{yyyy}/{mm}/{uuid}.{ext} on a private disk,
 * so it is reachable only through the admin-authenticated download route; the
 * stored `file_path` is disk-relative and never exposed to a view.
 * `generated_at` is stamped explicitly in PHP as UTC (never the DB default).
 */
class ReportService
{
    /**
     * @var array<string,class-string<ReportBuilder>>
     */
    private const BUILDERS = [
        ReportType::ShiftWelfare->value => ShiftWelfareReportBuilder::class,
        ReportType::ComplianceSummary->value => ComplianceSummaryReportBuilder::class,
        ReportType::AlertHistory->value => AlertHistoryReportBuilder::class,
        ReportType::SiaLicenceStatus->value => SiaLicenceStatusReportBuilder::class,
        ReportType::ShiftAudit->value => ShiftAuditReportBuilder::class,
        ReportType::WtrCompliance->value => WtrComplianceReportBuilder::class,
    ];

    /**
     * Generate, persist and record a report.
     *
     * Generation is format-agnostic: it always builds the report ONCE, stores a
     * frozen pure-array snapshot on the row (the source of truth for the
     * on-screen page and any download), and persists the primary PDF artifact to
     * disk. The format the viewer eventually downloads (PDF/CSV) is chosen later
     * on the report page and rendered from that same frozen snapshot.
     *
     * @param  array<string,mixed>  $params  builder inputs (already validated)
     * @throws ReportGenerationException on any user-facing failure
     */
    public function generate(ReportType $type, array $params, ?string $adminId): Report
    {
        $builder = $this->builderFor($type);
        $prepared = $builder->build($params);

        // Stamp the generation time up front so it is authoritative (explicit
        // UTC) AND available to the header/footer that renders below.
        $now = Carbon::now();
        $uuid = (string) Str::uuid();

        // The frozen snapshot: provenance meta + the computed section data + the
        // tabular CSV structure. Everything the page/downloads need, captured
        // once so it can never drift from the moment of generation.
        $snapshot = [
            'meta' => [
                'reference_id' => $this->referenceId($now, $uuid),
                'generated_at' => $now->utc()->format('Y-m-d\TH:i:s\Z'),
                'generated_by' => $this->adminName($adminId),
            ],
            'data' => $prepared['view_data'],
            'csv' => $prepared['csv'] ?? null,
            'slug' => $prepared['slug'] ?? 'report',
        ];

        // Persist the primary PDF artifact to the private disk.
        $path = sprintf('%s/%s/%s.pdf', $now->format('Y'), $now->format('m'), $uuid);
        Storage::disk('reports')->put(
            $path,
            $this->renderPdf($prepared['view'], $snapshot)
        );

        return Report::create([
            'id' => $uuid,
            'shift_id' => $prepared['shift_id'] ?? null,
            'generated_by' => $adminId,
            'report_type' => $type->value,
            'file_path' => $path,
            'payload' => $snapshot,
            // Authoritative, explicit UTC — never the DB CURRENT_TIMESTAMP default.
            'generated_at' => $now,
        ]);
    }

    /**
     * Re-render a stored report's PDF from its frozen snapshot (used when the
     * on-disk file is missing, or to serve it in the viewer's own timezone).
     *
     * $tz is a display preference only — the stored data is always UTC; the
     * frozen ISO timestamps are converted to $tz at render (TIMESTAMP_HANDLING.md).
     */
    public function renderStoredPdf(Report $report, string $tz = 'UTC'): string
    {
        $type = $report->type();
        if ($type === null || !is_array($report->payload)) {
            throw new ReportGenerationException('This report cannot be rendered.');
        }

        return $this->renderPdf($type->pdfView(), $report->payload, $tz);
    }

    /**
     * Render a stored report's CSV from its frozen snapshot. Every type carries a
     * CSV structure; any ISO-UTC timestamp cell is localised to $tz on the way
     * out (a display preference — the frozen data stays UTC).
     */
    public function renderStoredCsv(Report $report, string $tz = 'UTC'): string
    {
        $csv = $report->payload['csv'] ?? null;

        if (!is_array($csv) || !isset($csv['headers'])) {
            throw new ReportGenerationException(($report->typeLabel()) . ' has no CSV export.');
        }

        return CsvWriter::toString($csv['headers'], $this->localizeCells($csv['rows'] ?? [], $tz));
    }

    /**
     * Render a snapshot to a PDF byte string. `$snapshot` carries `meta` (with an
     * ISO generated_at) and `data` (the section arrays); the PDF header/footer
     * needs a Carbon, so meta is adapted here. `$tz` is threaded into the view so
     * the (otherwise-static) PDF can print times in the viewer's timezone.
     *
     * @param  array<string,mixed>  $snapshot
     */
    private function renderPdf(string $view, array $snapshot, string $tz = 'UTC'): string
    {
        $meta = $snapshot['meta'] ?? [];
        $pdfMeta = [
            'reference_id' => $meta['reference_id'] ?? null,
            'generated_at' => isset($meta['generated_at']) ? Carbon::parse($meta['generated_at']) : Carbon::now(),
            'generated_by' => $meta['generated_by'] ?? 'IronLock Admin',
        ];

        $data = ($snapshot['data'] ?? []) + [
            'meta' => $pdfMeta,
            'report_tz' => $tz,
            'report_tz_label' => $this->tzLabel($tz),
        ];

        return Pdf::loadView($view, $data)->setPaper('a4')->output();
    }

    /**
     * A short, human timezone label for the PDF (e.g. "Asia/Kolkata (UTC+05:30)"),
     * or just "UTC".
     */
    private function tzLabel(string $tz): string
    {
        if ($tz === 'UTC') {
            return 'UTC';
        }

        try {
            return $tz . ' (UTC' . Carbon::now($tz)->format('P') . ')';
        } catch (\Throwable $e) {
            return $tz;
        }
    }

    /**
     * Localise every ISO-8601 UTC timestamp cell (…Z) in a set of CSV rows to the
     * target timezone, formatting it as "Y-m-d H:i:s". Non-timestamp cells (text,
     * numbers, date-only strings) pass through untouched, so only real timestamps
     * are converted.
     *
     * @param  iterable<array>  $rows
     * @return list<array>
     */
    private function localizeCells(iterable $rows, string $tz): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = array_map(function ($cell) use ($tz) {
                if (is_string($cell) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z$/', $cell)) {
                    return Carbon::parse($cell)->setTimezone($tz)->format('Y-m-d H:i:s');
                }

                return $cell;
            }, (array) $row);
        }

        return $out;
    }

    /**
     * A human, sortable report reference for the header (e.g. RPT-20260701-A1B2).
     */
    private function referenceId(Carbon $now, string $uuid): string
    {
        return 'RPT-' . $now->format('Ymd') . '-' . strtoupper(substr(str_replace('-', '', $uuid), 0, 4));
    }

    /**
     * The generator's display name, resolved from the acting admin id.
     */
    private function adminName(?string $adminId): string
    {
        $name = 'IronLock Admin';
        if ($adminId) {
            $admin = \App\Domains\Admins\Models\Admin::find($adminId);
            if ($admin) {
                $name = $admin->name ?: ($admin->email ?: $name);
            }
        }

        return $name;
    }

    private function builderFor(ReportType $type): ReportBuilder
    {
        $class = self::BUILDERS[$type->value] ?? null;

        if ($class === null) {
            throw new ReportGenerationException('Unknown report type.');
        }

        return app($class);
    }

    /**
     * A friendly download filename for a stored report, built at download time
     * from the row (the report_type + generated_at), since the table does not
     * persist the original slug.
     */
    public function downloadFilename(Report $report, ?string $format = null): string
    {
        $slugBase = $report->payload['slug'] ?? ($report->type()?->label() ?? 'report');
        $slug = trim(strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $slugBase) ?? ''), '-') ?: 'report';
        $stamp = optional($report->generated_at)->format('Ymd-His') ?? Carbon::now()->format('Ymd-His');
        $ext = $format ?: $report->format();

        return sprintf('ironlock-%s-%s.%s', $slug, $stamp, $ext);
    }
}
