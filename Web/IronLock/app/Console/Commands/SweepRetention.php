<?php

namespace App\Console\Commands;

use App\Domains\Alerts\Models\Alert;
use App\Domains\Photos\Models\PhotoEvidence;
use App\Domains\Reports\Models\Report;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Data-retention sweep (Phase 8 · SEC-004).
 *
 * Prunes regenerable / past-policy data older than the horizons in
 * config('ironlock.retention'). Every horizon is a policy hook — a value of 0
 * means "keep indefinitely" and that category is skipped, so nothing is purged
 * unless explicitly configured.
 *
 * It NEVER touches the append-only audit trail (shift_events) — that history is
 * legally required and immutable. Categories:
 *   - reports  (report_days, default 365): generated PDF/CSV artifacts + files.
 *              Safe because any report can be regenerated from source data.
 *   - alerts   (alert_days, default 0): ACKNOWLEDGED (closed) alerts only; OPEN
 *              alerts are always kept.
 *   - evidence (evidence_days, default 0): raw photo evidence files + rows.
 *              Disabled by default — evidence archival is normally governed by
 *              the monthly backup lifecycle; enable only for a hard-deletion
 *              policy.
 *
 * Scheduled daily; safe to run by hand. Modelled on CleanupBackups.
 */
class SweepRetention extends Command
{
    protected $signature = 'retention:sweep';

    protected $description = 'Prune regenerable/past-policy data (reports, closed alerts, evidence) past their retention horizons';

    public function handle(): int
    {
        $now = Carbon::now();

        $reports = $this->pruneReports($now);
        $alerts = $this->pruneAlerts($now);
        $evidence = $this->pruneEvidence($now);

        $this->info("Retention sweep complete — reports: {$reports}, closed alerts: {$alerts}, evidence: {$evidence}.");

        return self::SUCCESS;
    }

    /**
     * Delete generated reports (row + stored file) past the report horizon.
     */
    private function pruneReports(Carbon $now): int
    {
        $days = (int) config('ironlock.retention.report_days', 0);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = $now->copy()->subDays($days);
        $removed = 0;

        Report::where('generated_at', '<', $cutoff)
            ->orderBy('generated_at')
            ->chunkById(200, function ($reports) use (&$removed) {
                foreach ($reports as $report) {
                    if (!empty($report->file_path) && Storage::disk('reports')->exists($report->file_path)) {
                        Storage::disk('reports')->delete($report->file_path);
                    }
                    $report->delete();
                    $removed++;
                }
            });

        return $removed;
    }

    /**
     * Delete acknowledged (closed) alerts past the alert horizon. OPEN alerts
     * are never pruned — an unactioned alert must survive regardless of age.
     */
    private function pruneAlerts(Carbon $now): int
    {
        $days = (int) config('ironlock.retention.alert_days', 0);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = $now->copy()->subDays($days);

        return Alert::where('status', 'ACKNOWLEDGED')
            ->where('raised_at', '<', $cutoff)
            ->delete();
    }

    /**
     * Delete raw photo evidence (row + stored file, and its dependent review)
     * past the evidence horizon. Disabled by default. The parent photo_request
     * and all shift_events are left intact — only the heavy image + its evidence
     * row are removed.
     */
    private function pruneEvidence(Carbon $now): int
    {
        $days = (int) config('ironlock.retention.evidence_days', 0);
        if ($days <= 0) {
            return 0;
        }

        $cutoff = $now->copy()->subDays($days);
        $removed = 0;

        PhotoEvidence::where('created_at', '<', $cutoff)
            ->orderBy('created_at')
            ->chunkById(200, function ($items) use (&$removed) {
                foreach ($items as $evidence) {
                    DB::transaction(function () use ($evidence, &$removed) {
                        if (!empty($evidence->file_path) && Storage::disk('photos')->exists($evidence->file_path)) {
                            Storage::disk('photos')->delete($evidence->file_path);
                        }
                        // Remove the dependent review first (FK) before the row.
                        DB::table('photo_reviews')->where('photo_evidence_id', $evidence->id)->delete();
                        $evidence->delete();
                        $removed++;
                    });
                }
            });

        return $removed;
    }
}
