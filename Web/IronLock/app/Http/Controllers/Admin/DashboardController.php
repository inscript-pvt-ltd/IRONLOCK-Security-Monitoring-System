<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Admins\Models\Admin;
use App\Domains\Alerts\Models\Alert;
use App\Domains\Alerts\Services\AlertService;
use App\Domains\GPS\Models\GuardLocation;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Wakefulness\Models\WakefulnessCheck;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    /**
     * Show the admin dashboard home.
     */
    public function index()
    {
        // Get current admin — if the session references a missing/stale admin,
        // bounce to login rather than passing null into the view.
        $admin = Admin::find($this->currentAdminId());

        if (!$admin) {
            session()->flush();
            return redirect()
                ->route('admin.login')
                ->with('error', 'Your session has expired. Please log in again.');
        }

        // Dashboard data — all backed by real queries (Phase 3.3). A failure in
        // any one section (e.g. transient DB error) is logged and degraded to a
        // safe default so the page still renders rather than returning a 500.
        try {
            $stats = $this->getDashboardStats();
        } catch (\Throwable $e) {
            report($e);
            $stats = ['active_guards' => 0, 'critical_alerts' => 0, 'comms_interrupted' => 0, 'pending_acks' => 0];
        }

        try {
            $alerts = $this->getRecentAlerts();
        } catch (\Throwable $e) {
            report($e);
            $alerts = [];
        }

        try {
            $active_guards = $this->getActiveGuards();
        } catch (\Throwable $e) {
            report($e);
            $active_guards = [];
        }

        return view('admin.dashboard.index', compact(
            'admin',
            'stats',
            'alerts',
            'active_guards'
        ));
    }

    /**
     * Admin notification feed (polled by the topbar bell on every admin page).
     *
     * Surfaces the two things a supervisor must act on:
     *  - Early-finish requests (BACKEND_SHIFT_END_SPEC §0.2): a guard has asked
     *    to leave before scheduled_end and cannot end until approved/rejected.
     *    "View" opens the shift timeline where the decision is made.
     *  - Shifts needing resolution: a missed shift, or one ended early, that no
     *    supervisor has resolved yet (the same red ⚠ items on the calendar).
     *    "Resolve" deep-links to the shifts page and opens the resolve drawer.
     *
     * Returns a single list newest-first plus a count for the badge. Built to
     * grow — wakefulness/zone/photo alerts can join this feed later.
     */
    public function notifications(): JsonResponse
    {
        $guardName = function (Shift $shift): string {
            $guard = $shift->assignedGuard;
            $name = $guard ? trim($guard->first_name . ' ' . $guard->last_name) : '';
            return $name !== '' ? $name : 'Unassigned';
        };

        // Pending early-finish requests awaiting an approve/reject decision.
        $earlyEnd = Shift::with(['assignedGuard', 'site'])
            ->where('early_end_status', Shift::EARLY_END_PENDING)
            ->orderByDesc('early_end_requested_at')
            ->get()
            ->map(function (Shift $shift) use ($guardName) {
                return [
                    'type' => 'early_end',
                    'shift_id' => $shift->id,
                    'reference' => $shift->reference,
                    'title' => 'Early finish · ' . $guardName($shift),
                    'site_name' => $shift->site->name ?? '—',
                    'detail' => $shift->early_end_reason ? 'Reason: ' . $shift->early_end_reason : null,
                    'at' => optional($shift->early_end_requested_at)->toISOString(),
                    'action_url' => route('admin.shifts.timeline', $shift->id),
                    'action_label' => 'View',
                ];
            });

        // Unresolved missed / ended-early shifts (mirrors Shift::needsResolution()).
        $needsResolution = Shift::with(['assignedGuard', 'site'])
            ->whereNull('resolved_at')
            ->where(function ($q) {
                $q->where('status', Shift::STATUS_MISSED)
                  ->orWhere(function ($q2) {
                      $q2->where('status', Shift::STATUS_COMPLETED)
                         ->where('ended_early', true);
                  });
            })
            ->orderByDesc('scheduled_start')
            ->get()
            ->map(function (Shift $shift) use ($guardName) {
                $kind = $shift->resolutionKind();
                $titleMap = [
                    'never_checked_in' => 'Missed — no check-in',
                    'checked_in_no_start' => 'Missed — never started',
                    'ended_early' => 'Ended early',
                ];
                // The representative time: when the guard was due (missed) or when
                // they left (ended early).
                $at = $kind === 'ended_early' ? $shift->actual_end : $shift->scheduled_start;

                return [
                    'type' => 'resolve',
                    'shift_id' => $shift->id,
                    'reference' => $shift->reference,
                    'title' => ($titleMap[$kind] ?? 'Needs resolution') . ' · ' . $guardName($shift),
                    'site_name' => $shift->site->name ?? '—',
                    'detail' => null,
                    'at' => optional($at)->toISOString(),
                    'action_url' => route('admin.shifts.index', ['resolve' => $shift->id]),
                    'action_label' => 'Resolve',
                ];
            });

        // One stream, newest-first. Items without a timestamp sort last.
        $notifications = $earlyEnd->concat($needsResolution)
            ->sortByDesc(fn ($n) => $n['at'] ?? '')
            ->values();

        return response()->json([
            'success' => true,
            'count' => $notifications->count(),
            'notifications' => $notifications,
        ]);
    }

    /**
     * Live guard positions for the dashboard map — polled every 15s.
     *
     * Returns one entry per active shift with its guard's last-known location,
     * derived zone status (INSIDE_ZONE / OUTSIDE_ZONE / COMMS_INTERRUPTED) and
     * the site's geofence polygon for rendering. A guard with no ping yet has
     * null coordinates; a guard whose last ping is stale (> 30s) is flagged
     * comms_interrupted rather than placed inside/outside.
     *
     * Shared by the dashboard mini-map and the full Live Map page (D-08); the
     * latter's marker side-panel also reads the shift reference/start, the
     * wakefulness welfare tally, and whether the guard has an open alert
     * (driving the "bold pin = unresponsive" styling + the Open Alert button).
     */
    public function liveGuards(): JsonResponse
    {
        $shifts = Shift::with(['assignedGuard', 'site', 'geofence'])
            ->where('status', Shift::STATUS_ACTIVE)
            ->get();

        $locations = GuardLocation::whereIn('guard_id', $shifts->pluck('guard_id'))
            ->get()
            ->keyBy('guard_id');

        // Wakefulness welfare tally per active shift (one grouped query, no N+1).
        $wakeTallies = WakefulnessCheck::whereIn('shift_id', $shifts->pluck('id'))
            ->selectRaw(
                "shift_id,
                 SUM(result = ?) AS confirmed,
                 SUM(result = ?) AS failed,
                 COUNT(*) AS total",
                [WakefulnessCheck::RESULT_CONFIRMED, WakefulnessCheck::RESULT_FAILED]
            )
            ->groupBy('shift_id')
            ->get()
            ->keyBy('shift_id');

        // Guards currently carrying an open alert → rendered with a bold pin and
        // offered an "Open Alert" deep link on the Live Map side panel.
        $openAlertGuardIds = Alert::whereIn('guard_id', $shifts->pluck('guard_id'))
            ->where('status', 'OPEN')
            ->pluck('guard_id')
            ->flip();

        $guards = $shifts->map(function (Shift $shift) use ($locations, $wakeTallies, $openAlertGuardIds) {
            $location = $locations->get($shift->guard_id);
            $guard = $shift->assignedGuard;
            $comms = !$location || $location->isCommsInterrupted();
            $tally = $wakeTallies->get($shift->id);

            $zoneStatus = $comms ? 'COMMS_INTERRUPTED' : ($location->zone_status ?? 'UNKNOWN');

            return [
                'guard_id' => $shift->guard_id,
                'guard_name' => $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unknown',
                'shift_id' => $shift->id,
                'shift_reference' => $shift->reference,
                'shift_started_at' => $shift->actual_start?->toISOString(),
                'site_id' => $shift->site_id,
                'site_name' => $shift->site?->name ?? '',
                'latitude' => $location ? (float) $location->latitude : null,
                'longitude' => $location ? (float) $location->longitude : null,
                'battery_level' => $location?->battery_level,
                'zone_status' => $zoneStatus,
                'comms_interrupted' => $comms,
                'has_open_alert' => $openAlertGuardIds->has($shift->guard_id),
                'wakefulness' => [
                    'confirmed' => (int) ($tally->confirmed ?? 0),
                    'failed' => (int) ($tally->failed ?? 0),
                    'total' => (int) ($tally->total ?? 0),
                ],
                'last_seen_at' => $location?->updated_at?->toISOString(),
                'last_seen_human' => $location?->updated_at?->diffForHumans() ?? 'Never',
                'geofence_coordinates' => $shift->geofence?->getPolygonCoordinates(),
            ];
        })->values();

        return response()->json(['success' => true, 'data' => ['guards' => $guards]]);
    }

    /**
     * Dashboard KPI counts (Phase 3.3 — real data).
     *
     * `comms_interrupted` MUST use the same definition as the active-guards
     * table (getActiveGuards): a guard on an ACTIVE shift whose live location is
     * missing or stale. Counting stale `guard_locations` rows directly would be
     * wrong both ways — that table keeps one permanent row per guard (no
     * history, never deleted), so it would count guards from long-ended shifts
     * AND miss active guards who haven't pinged yet (no row at all).
     */
    private function getDashboardStats(): array
    {
        $activeGuardIds = Shift::where('status', Shift::STATUS_ACTIVE)->pluck('guard_id');

        $liveLocations = GuardLocation::whereIn('guard_id', $activeGuardIds)
            ->get()
            ->keyBy('guard_id');

        $commsInterrupted = $activeGuardIds->filter(function ($guardId) use ($liveLocations) {
            $location = $liveLocations->get($guardId);
            return !$location || $location->isCommsInterrupted();
        })->count();

        return [
            // Employed guards on the roster (employment_status = active). This is
            // a headcount of the workforce, NOT how many are currently on duty —
            // the live map and the active-guards table below show who is on an
            // active shift right now.
            'active_guards' => Guard::where('employment_status', 'active')->count(),
            'critical_alerts' => Alert::where('status', 'OPEN')->where('severity', 'CRITICAL')->count(),
            // Active guards whose live position is missing or older than the comms timeout.
            'comms_interrupted' => $commsInterrupted,
            // Open (unacknowledged) alerts awaiting a supervisor.
            'pending_acks' => Alert::where('status', 'OPEN')->count(),
        ];
    }

    /**
     * Open alerts for the dashboard, delegated to the alert service.
     */
    private function getRecentAlerts(): array
    {
        return app(AlertService::class)->getActiveAlerts();
    }

    /**
     * Active guards with their live zone status for the dashboard table.
     */
    private function getActiveGuards(): array
    {
        $shifts = Shift::with(['assignedGuard', 'site'])
            ->where('status', Shift::STATUS_ACTIVE)
            ->get();

        $locations = GuardLocation::whereIn('guard_id', $shifts->pluck('guard_id'))
            ->get()
            ->keyBy('guard_id');

        // Shifts whose guard currently has an open zone-exit alert.
        $alertedShiftIds = Alert::whereIn('shift_id', $shifts->pluck('id'))
            ->where('type', 'ZONE_EXIT')
            ->where('status', 'OPEN')
            ->pluck('shift_id')
            ->flip();

        return $shifts->map(function (Shift $shift) use ($locations, $alertedShiftIds) {
            $location = $locations->get($shift->guard_id);
            $guard = $shift->assignedGuard;

            if (!$location || $location->isCommsInterrupted()) {
                $statusClass = 'interrupted';
                $statusText = '⊘ Comms Interrupted';
            } elseif ($location->zone_status === 'OUTSIDE_ZONE') {
                $hasAlert = $alertedShiftIds->has($shift->id);
                $statusClass = $hasAlert ? 'unresponsive' : 'outside';
                $statusText = $hasAlert ? '✗ Zone Exit Alert' : '⚠ Outside Zone';
            } else {
                $statusClass = 'inside';
                $statusText = '✓ Inside Zone';
            }

            return [
                'id' => $shift->guard_id,
                'shift_id' => $shift->id,
                'name' => $guard ? trim($guard->first_name . ' ' . $guard->last_name) : 'Unknown',
                'site_name' => $shift->site?->name ?? 'No Site',
                'zone_status_class' => $statusClass,
                'zone_status_text' => $statusText,
                'last_gps' => $location?->updated_at ? $location->updated_at->diffForHumans() : 'Never',
            ];
        })->values()->toArray();
    }
}
