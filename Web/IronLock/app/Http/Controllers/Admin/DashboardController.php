<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    /**
     * Show the admin dashboard home.
     */
    public function index()
    {
        // Get current admin
        $admin = Admin::find(session('admin_id'));

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
