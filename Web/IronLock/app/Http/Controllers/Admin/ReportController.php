<?php

namespace App\Http\Controllers\Admin;

use App\Domains\Guards\Models\Guard;
use App\Domains\Reports\Exceptions\ReportGenerationException;
use App\Domains\Reports\Models\Report;
use App\Domains\Reports\Services\ReportService;
use App\Domains\Reports\Support\ReportType;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Sites\Models\Site;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

/**
 * Admin Reports & Exports (Phase 8 · D-09). Admin-auth only (route group).
 *
 * Generation is synchronous and format-agnostic: POST /reports/generate builds +
 * persists a report (a frozen snapshot + a PDF on the private disk) and returns
 * the URL of its on-screen page. The viewer opens that page and downloads PDF or
 * CSV from there — both rendered from the same frozen snapshot, so nothing ever
 * drifts. Files are reachable only through the admin-gated routes; no disk path
 * is exposed to any view.
 */
class ReportController extends Controller
{
    /**
     * Previous Exports page size. The list is paginated server-side so it stays
     * fast and compact no matter how many reports have accumulated over time
     * (older exports used to fall off a hard 50-row cap and become unreachable).
     */
    private const EXPORTS_PER_PAGE = 15;

    public function __construct(private readonly ReportService $reports) {}

    /**
     * GET /admin/reports — the Reports & Exports page (D-09).
     */
    public function index()
    {
        $types = ReportType::cases();

        $guards = Guard::where('employment_status', 'active')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'last_name', 'employee_code']);

        $sites = Site::orderBy('name')->get(['id', 'name']);

        // Recent shifts for the shift-scoped report pickers (Welfare / Audit).
        // Newest first; bounded so the dropdown stays usable.
        $shifts = Shift::with('assignedGuard:id,first_name,last_name')
            ->orderByDesc('scheduled_start')
            ->limit(150)
            ->get(['id', 'reference', 'guard_id', 'status', 'scheduled_start']);

        // First page of the Previous Exports list; subsequent pages are fetched
        // as JSON by the pager (@see list()). Both render the same _rows partial.
        $previous = $this->previousExportsQuery()->paginate(self::EXPORTS_PER_PAGE);

        return view('admin.reports.index', compact('types', 'guards', 'sites', 'shifts', 'previous'));
    }

    /**
     * GET /admin/reports/list — one page of the Previous Exports list as JSON
     * (rendered rows HTML + pagination meta). Powers the Prev/Next pager and the
     * post-delete refresh without a full page reload.
     */
    public function list(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));

        $previous = $this->previousExportsQuery()->paginate(self::EXPORTS_PER_PAGE, ['*'], 'page', $page);

        // If a delete emptied the requested page (the page now sits past the end),
        // fall back to the real last page so the pager never lands on a blank list.
        if ($previous->currentPage() > $previous->lastPage()) {
            $previous = $this->previousExportsQuery()
                ->paginate(self::EXPORTS_PER_PAGE, ['*'], 'page', $previous->lastPage());
        }

        return response()->json([
            'success' => true,
            'html' => view('admin.reports._rows', compact('previous'))->render(),
            'pagination' => [
                'current_page' => $previous->currentPage(),
                'last_page' => $previous->lastPage(),
                'total' => $previous->total(),
            ],
        ]);
    }

    /**
     * The Previous Exports base query (newest first, with the relations the row
     * partial renders). Shared by the initial page and the paged JSON endpoint so
     * ordering and eager-loads can never drift between them.
     */
    private function previousExportsQuery()
    {
        return Report::with(['shift:id,reference', 'generatedBy:id,name'])
            ->orderByDesc('generated_at');
    }

    /**
     * POST /admin/reports/generate — build, persist and record a report, then
     * return its on-screen page URL (the page offers the downloads).
     */
    public function generate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'report_type' => ['required', 'string', 'in:' . implode(',', array_map(fn ($t) => $t->value, ReportType::cases()))],
            'shift_id' => ['nullable', 'uuid', 'exists:shifts,id'],
            'guard_id' => ['nullable', 'uuid', 'exists:guards,id'],
            'site_id' => ['nullable', 'uuid', 'exists:sites,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'reference_date' => ['nullable', 'date'],
            'include_nonce' => ['nullable', 'boolean'],
            'include_hashes' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $type = ReportType::from($request->input('report_type'));

        try {
            $report = $this->reports->generate($type, [
                'shift_id' => $request->input('shift_id'),
                'guard_id' => $request->input('guard_id'),
                'site_id' => $request->input('site_id'),
                'date_from' => $request->input('date_from'),
                'date_to' => $request->input('date_to'),
                'reference_date' => $request->input('reference_date'),
                'include_nonce' => $request->boolean('include_nonce'),
                'include_hashes' => $request->boolean('include_hashes'),
            ], $this->currentAdminId());
        } catch (ReportGenerationException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Report generation failed', ['type' => $type->value, 'exception' => $e]);
            return response()->json(['success' => false, 'error' => 'Unable to generate the report. Please try again.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => $type->label() . ' generated.',
            'report' => $this->reportPayload($report),
        ]);
    }

    /**
     * POST /admin/shifts/{shift}/report — one-click Shift Welfare Report from
     * the Shift Detail page (Component D). Returns its on-screen page URL.
     */
    public function shiftReport(string $shift): JsonResponse
    {
        $model = Shift::find($shift);
        if (!$model) {
            return response()->json(['success' => false, 'error' => 'Shift not found.'], 404);
        }

        try {
            $report = $this->reports->generate(
                ReportType::ShiftWelfare,
                ['shift_id' => $model->id],
                $this->currentAdminId()
            );
        } catch (ReportGenerationException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            Log::error('Shift welfare report failed', ['shift_id' => $model->id, 'exception' => $e]);
            return response()->json(['success' => false, 'error' => 'Unable to generate the report. Please try again.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Shift Welfare Report generated.',
            'report' => $this->reportPayload($report),
        ]);
    }

    /**
     * GET /admin/reports/{report} — the on-screen report page (the "slug" page),
     * rendered from the frozen snapshot. Localises timestamps in the browser.
     */
    public function show(string $report)
    {
        $model = Report::with(['shift:id,reference', 'generatedBy:id,name'])->find($report);
        $type = $model?->type();

        if (!$model || $type === null || !is_array($model->payload)) {
            abort(404, 'This report is no longer available.');
        }

        return view($type->webView(), [
            'report' => $model,
            'meta' => $model->payload['meta'] ?? [],
            'data' => $model->payload['data'] ?? [],
        ]);
    }

    /**
     * GET /admin/reports/{report}/download?format=pdf|csv — stream a report in
     * the requested format. PDF serves the persisted artifact (re-rendered from
     * the snapshot if the file is gone); CSV renders from the snapshot on demand.
     */
    public function download(string $report, Request $request)
    {
        $model = Report::find($report);
        if (!$model || !is_array($model->payload)) {
            abort(404, 'This report is no longer available.');
        }

        $format = strtolower((string) $request->query('format', 'pdf'));
        if (!in_array($format, $model->downloadFormats(), true)) {
            abort(404, 'This report has no ' . strtoupper($format) . ' export.');
        }

        // The viewer's own timezone (a display preference sent by the report page);
        // the stored data stays UTC, times are converted at render.
        $tz = $this->resolveTimezone($request->query('tz'));
        $filename = $this->reports->downloadFilename($model, $format);

        if ($format === 'csv') {
            try {
                $csv = $this->reports->renderStoredCsv($model, $tz);
            } catch (ReportGenerationException $e) {
                abort(404, $e->getMessage());
            }

            return response($csv, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]);
        }

        // PDF: the cached artifact is the UTC baseline — serve it only when the
        // viewer wants UTC. For any other timezone, re-render from the frozen
        // snapshot so the times read in the viewer's own zone.
        if ($tz === 'UTC' && !empty($model->file_path) && Storage::disk('reports')->exists($model->file_path)) {
            return response()->download(Storage::disk('reports')->path($model->file_path), $filename);
        }

        return response($this->reports->renderStoredPdf($model, $tz), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Validate a caller-supplied IANA timezone, falling back to UTC. Only a real
     * timezone identifier is honoured — this is a display preference, never a
     * source of authoritative time.
     */
    private function resolveTimezone(mixed $tz): string
    {
        $tz = is_string($tz) ? trim($tz) : '';

        return ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) ? $tz : 'UTC';
    }

    /**
     * DELETE /admin/reports/{report} — remove a single persisted report (row +
     * its private file). The immutable audit trail (shift_events etc.) is
     * untouched; only the regenerable report artifact is deleted.
     */
    public function destroy(string $report): JsonResponse
    {
        $model = Report::find($report);
        if (!$model) {
            return response()->json(['success' => false, 'error' => 'Report not found.'], 404);
        }

        $this->deleteReport($model);

        return response()->json(['success' => true]);
    }

    /**
     * POST /admin/reports/bulk-delete — remove several reports at once.
     * Body: { ids: [uuid, …] }.
     */
    public function bulkDestroy(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? $v : (string) $v,
            (array) $request->input('ids', [])
        )));

        if (empty($ids)) {
            return response()->json(['success' => false, 'error' => 'No reports selected.'], 422);
        }

        $deleted = 0;
        foreach (Report::whereIn('id', $ids)->get() as $model) {
            $this->deleteReport($model);
            $deleted++;
        }

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    /**
     * Delete a report's private file (if present) then its row. The audit trail
     * it was built from is never touched.
     */
    private function deleteReport(Report $report): void
    {
        if (!empty($report->file_path) && Storage::disk('reports')->exists($report->file_path)) {
            Storage::disk('reports')->delete($report->file_path);
        }

        $report->delete();
    }

    /**
     * The safe front-end shape of a report row (no raw file_path). The page URL
     * is where generation now redirects; download URLs are offered on that page.
     */
    private function reportPayload(Report $report): array
    {
        return [
            'id' => $report->id,
            'type' => $report->report_type,
            'type_label' => $report->typeLabel(),
            'shift_reference' => $report->shift->reference ?? null,
            'generated_by' => $report->generatedBy->name ?? '—',
            'generated_at' => optional($report->generated_at)->toIso8601String(),
            'view_url' => $report->viewUrl(),
            'download_url' => route('admin.reports.download', ['report' => $report->id, 'format' => 'pdf']),
        ];
    }
}
