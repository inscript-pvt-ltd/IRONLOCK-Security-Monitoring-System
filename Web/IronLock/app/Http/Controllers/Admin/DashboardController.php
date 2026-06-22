<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Get dashboard statistics
        $stats = $this->getDashboardStats();

        // Get recent alerts (mocked for now)
        $recentAlerts = $this->getRecentAlerts();

        // Get active guards
        $activeGuards = $this->getActiveGuards();

        // Get alerts and active guards with proper data structure
        $alerts = $this->getRecentAlerts();
        $active_guards = $this->getActiveGuards();

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
     * Get dashboard statistics.
     */
    private function getDashboardStats(): array
    {
        return [
            'active_guards' => Guard::where('employment_status', 'active')->count(),
            'critical_alerts' => 2, // Mocked for now
            'comms_interrupted' => 1, // Mocked for now
            'pending_acks' => 4, // Mocked for now
        ];
    }

    /**
     * Get recent alerts (mocked for Phase 2).
     */
    private function getRecentAlerts(): array
    {
        return [
            [
                'id' => 'alert-1',
                'type' => 'GUARD_UNRESPONSIVE',
                'severity' => 'CRITICAL',
                'guard_name' => 'Guard B',
                'site_name' => 'Site A',
                'title' => 'Wakefulness failed — no response',
                'age' => '4m ago'
            ],
            [
                'id' => 'alert-2',
                'type' => 'ZONE_EXIT',
                'severity' => 'CRITICAL',
                'guard_name' => 'Guard C',
                'site_name' => 'North Bay',
                'title' => 'Guard outside geofence > grace period (5 min)',
                'age' => '8m ago'
            ],
            [
                'id' => 'alert-3',
                'type' => 'PHOTO_TIMEOUT',
                'severity' => 'WARNING',
                'guard_name' => 'Mary Tang',
                'site_name' => 'North Bay',
                'title' => '90s response window expired',
                'age' => '2m ago'
            ],
            [
                'id' => 'alert-4',
                'type' => 'CLOCK_MANIPULATION_SUSPECTED',
                'severity' => 'WARNING',
                'guard_name' => 'Chris K',
                'site_name' => 'East Court',
                'title' => 'NTP/EXIF delta > 30s threshold',
                'age' => '15m ago'
            ]
        ];
    }

    /**
     * Get active guards (mocked for Phase 2).
     */
    private function getActiveGuards(): array
    {
        return [
            [
                'id' => 'guard-1',
                'name' => 'John Smith',
                'site_name' => 'Westfield A',
                'zone_status_class' => 'inside',
                'zone_status_text' => '✓ Inside Zone',
                'last_gps' => '8s ago'
            ],
            [
                'id' => 'guard-2',
                'name' => 'Mary Tang',
                'site_name' => 'North Bay',
                'zone_status_class' => 'outside',
                'zone_status_text' => '⚠ Outside Zone · grace: 2:14',
                'last_gps' => '22s ago'
            ],
            [
                'id' => 'guard-3',
                'name' => 'Guard B',
                'site_name' => 'Site A',
                'zone_status_class' => 'unresponsive',
                'zone_status_text' => '✗ Unresponsive',
                'last_gps' => '4m ago'
            ],
            [
                'id' => 'guard-4',
                'name' => 'Chris K',
                'site_name' => 'East Court',
                'zone_status_class' => 'interrupted',
                'zone_status_text' => '⊘ Comms Interrupted',
                'last_gps' => '12m ago'
            ]
        ];
    }
}
