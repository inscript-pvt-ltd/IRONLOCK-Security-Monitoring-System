<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Alerts\Models\Alert;
use App\Domains\Alerts\Services\AlertService;
use App\Domains\Guards\Models\Guard;
use App\Domains\Sites\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Alert Feed (D-03 · ALT-006 / ALT-007).
 *
 * The supervisor worklist: a filterable, paginated list of system alerts with a
 * slide-in detail panel and the manual acknowledgement workflow. Alerts stay
 * OPEN until an administrator acknowledges them with an outcome note (master
 * spec §"Alert Acknowledgement" — note required); acknowledgement is recorded
 * against the admin and never auto-cleared.
 *
 * The page shell renders server-side; the table refreshes itself via JSON
 * (list/show) on the same 15s cadence as the rest of the dashboard.
 */
class AlertController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    /**
     * The Alert Feed page shell + filter dropdown options.
     */
    public function index()
    {
        return view('admin.alerts.index', [
            'filterTypes' => Alert::query()->distinct()->orderBy('type')->pluck('type'),
            'filterSites' => Site::orderBy('name')->get(['id', 'name']),
            'filterGuards' => Guard::orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (Guard $g) => [
                    'id' => $g->id,
                    'name' => trim($g->first_name . ' ' . $g->last_name),
                ]),
        ]);
    }

    /**
     * Filtered + paginated rows (AJAX) — powers the table and its auto-refresh.
     */
    public function list(Request $request): JsonResponse
    {
        $filters = $request->only(['severity', 'type', 'site_id', 'guard_id', 'status', 'offline']);
        $perPage = (int) config('ironlock.alerts_feed_per_page', 25);

        $result = $this->alerts->getAlertsFiltered($filters, $perPage);

        return response()->json(['success' => true] + $result);
    }

    /**
     * One alert's detail for the slide-in panel (AJAX).
     */
    public function show(Alert $alert): JsonResponse
    {
        $detail = $this->alerts->getAlertDetail($alert->id);

        if (!$detail) {
            return response()->json(['success' => false, 'error' => 'NOT_FOUND'], 404);
        }

        return response()->json(['success' => true, 'alert' => $detail]);
    }

    /**
     * Acknowledge an open alert with a required outcome note. Idempotent — a
     * non-open alert returns a clean 409 rather than double-acking.
     */
    public function acknowledge(Request $request, Alert $alert): JsonResponse
    {
        $validated = $request->validate([
            'note' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $ok = $this->alerts->acknowledgeAlert(
            $alert->id,
            (string) $this->currentAdminId(),
            $validated['note']
        );

        if (!$ok) {
            return response()->json([
                'success' => false,
                'error' => 'ALREADY_RESOLVED',
                'message' => 'This alert is no longer open.',
            ], 409);
        }

        return response()->json([
            'success' => true,
            'open_count' => $this->alerts->openAlertCount(),
        ]);
    }

    /**
     * Acknowledge several open alerts at once with one shared outcome note
     * (ALT-008 bulk action). Each alert is acknowledged through the same
     * idempotent path as the single ack, so already-resolved ones are simply
     * skipped — the response reports how many were actually acknowledged.
     */
    public function bulkAcknowledge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string'],
            'note' => ['required', 'string', 'min:1', 'max:1000'],
        ]);

        $acknowledged = $this->alerts->acknowledgeAlerts(
            $validated['ids'],
            (string) $this->currentAdminId(),
            $validated['note']
        );

        return response()->json([
            'success' => true,
            'acknowledged' => $acknowledged,
            'open_count' => $this->alerts->openAlertCount(),
        ]);
    }

    /**
     * Open-alert count for the sidebar nav badge + the open-CRITICAL ids that
     * drive the global auditory cue (polled globally from the layout).
     */
    public function count(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'count' => $this->alerts->openAlertCount(),
            'critical_ids' => $this->alerts->openCriticalAlertIds(),
        ]);
    }
}
