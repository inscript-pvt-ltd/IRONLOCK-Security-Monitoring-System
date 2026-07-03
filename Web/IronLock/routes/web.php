<?php

use Illuminate\Support\Facades\Route;

// Redirect root to admin login
Route::get('/', function () {
    return redirect()->route('admin.login');
});

// Shift Access Link (SSO) landing page — PUBLIC, no auth. A supervisor sends the
// guard an https link (this URL); tapping it in WhatsApp/SMS opens the browser,
// and this page bounces into the mobile app's custom scheme so the app can
// redeem the token via POST /api/mobile/v1/auth/shift-access. This page is a
// doorway ONLY — it never consumes or validates the token (redemption happens
// app-side). The token is constrained to hex so nothing else reaches the view.
Route::get('m/shift-access/{token}', function (string $token) {
    return response()
        ->view('mobile.shift_access_redirect', [
            'token' => $token,
            'scheme' => rtrim((string) config('ironlock.shift_access_app_scheme', 'ironlock://shift-access/'), '/') . '/' . $token,
        ])
        // Don't let a proxy/CDN cache the bounce page.
        ->header('Cache-Control', 'no-store, no-cache, must-revalidate');
})->where('token', '[A-Fa-f0-9]{16,128}')->name('shift-access.open');

// Admin Authentication Routes
Route::prefix('admin')->name('admin.')->group(function () {
    // Public routes (no auth required)
    Route::get('login', [App\Http\Controllers\Admin\AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [App\Http\Controllers\Admin\AuthController::class, 'login']);
    Route::post('logout', [App\Http\Controllers\Admin\AuthController::class, 'logout'])->name('logout');

    // Protected routes (require admin auth)
    Route::middleware(['admin.auth'])->group(function () {
        Route::get('/', [App\Http\Controllers\Admin\DashboardController::class, 'index'])->name('dashboard');
        Route::get('dashboard', [App\Http\Controllers\Admin\DashboardController::class, 'index']);

        // Topbar notification feed (pending early-end requests today). Polled by
        // the bell in the admin layout on every page.
        Route::get('notifications', [App\Http\Controllers\Admin\DashboardController::class, 'notifications'])->name('notifications');

        // Live guard positions — polled every 15s by the dashboard map (Phase 3.3).
        Route::get('live-guards', [App\Http\Controllers\Admin\DashboardController::class, 'liveGuards'])->name('live-guards');

        // Live Map page (Phase 6 · D-08) — full-screen ops map; reuses live-guards.
        Route::get('live-map', [App\Http\Controllers\Admin\LiveMapController::class, 'index'])->name('live-map.index');

        // Alert Feed (Phase 6 · D-03). Static/action routes before the {alert}
        // parameter so `list`/`count` aren't swallowed by route-model binding.
        Route::get('alerts', [App\Http\Controllers\Admin\AlertController::class, 'index'])->name('alerts.index');
        Route::get('alerts/list', [App\Http\Controllers\Admin\AlertController::class, 'list'])->name('alerts.list');
        Route::get('alerts/count', [App\Http\Controllers\Admin\AlertController::class, 'count'])->name('alerts.count');
        // Bulk-acknowledge must be declared before the {alert} catch-all so the
        // literal segment isn't captured as an alert id.
        Route::post('alerts/bulk-acknowledge', [App\Http\Controllers\Admin\AlertController::class, 'bulkAcknowledge'])->name('alerts.bulkAcknowledge');
        Route::post('alerts/{alert}/acknowledge', [App\Http\Controllers\Admin\AlertController::class, 'acknowledge'])->name('alerts.acknowledge');
        Route::get('alerts/{alert}', [App\Http\Controllers\Admin\AlertController::class, 'show'])->name('alerts.show');

        // Guard Management routes - D-05 Wireframe with Drawer UI
        Route::get('guards', [App\Http\Controllers\Admin\GuardController::class, 'index'])->name('guards.index');
        Route::get('guards/list', [App\Http\Controllers\Admin\GuardController::class, 'list'])->name('guards.list'); // Must be before parameterized routes
        Route::post('guards', [App\Http\Controllers\Admin\GuardController::class, 'store'])->name('guards.store');
        Route::get('guards/{guard}/shifts', [App\Http\Controllers\Admin\GuardController::class, 'recentShifts'])->name('guards.shifts'); // AJAX: completed shifts for drawer
        Route::get('guards/{guard}', [App\Http\Controllers\Admin\GuardController::class, 'show'])->name('guards.show'); // AJAX endpoint
        Route::put('guards/{guard}', [App\Http\Controllers\Admin\GuardController::class, 'update'])->name('guards.update');
        Route::patch('guards/{guard}/toggle-status', [App\Http\Controllers\Admin\GuardController::class, 'toggleStatus'])->name('guards.toggle-status');
        // GDPR right-to-erasure (Phase 8): anonymise PII, preserve the audit
        // trail (distinct from destroy(), which hard-deletes the row).
        Route::post('guards/{guard}/erase', [App\Http\Controllers\Admin\GuardController::class, 'erase'])->name('guards.erase');
        Route::delete('guards/{guard}', [App\Http\Controllers\Admin\GuardController::class, 'destroy'])->name('guards.destroy');

        // Site Management routes - Phase 3: Core Location System
        Route::get('sites', [App\Http\Controllers\Admin\SiteController::class, 'index'])->name('sites.index');
        Route::get('sites/list', [App\Http\Controllers\Admin\SiteController::class, 'list'])->name('sites.list'); // Must be before parameterized routes
        Route::get('sites-list/active', [App\Http\Controllers\Admin\SiteController::class, 'getActiveSites'])->name('sites.active');
        Route::post('sites', [App\Http\Controllers\Admin\SiteController::class, 'store'])->name('sites.store');
        Route::get('sites/{site}', [App\Http\Controllers\Admin\SiteController::class, 'show'])->name('sites.show');
        Route::put('sites/{site}', [App\Http\Controllers\Admin\SiteController::class, 'update'])->name('sites.update');
        Route::post('sites/{site}/toggle-status', [App\Http\Controllers\Admin\SiteController::class, 'toggleStatus'])->name('sites.toggle-status');
        Route::delete('sites/{site}', [App\Http\Controllers\Admin\SiteController::class, 'destroy'])->name('sites.destroy');

        // Geofence Management routes - Phase 3: Polygon Boundaries
        Route::get('sites/{site}/geofences', [App\Http\Controllers\Admin\GeofenceController::class, 'index'])->name('geofences.index');
        Route::post('geofences', [App\Http\Controllers\Admin\GeofenceController::class, 'store'])->name('geofences.store');
        Route::get('geofences/{geofence}', [App\Http\Controllers\Admin\GeofenceController::class, 'show'])->name('geofences.show');
        Route::put('geofences/{geofence}', [App\Http\Controllers\Admin\GeofenceController::class, 'update'])->name('geofences.update');
        Route::post('geofences/{geofence}/toggle-status', [App\Http\Controllers\Admin\GeofenceController::class, 'toggleStatus'])->name('geofences.toggle-status');
        Route::delete('geofences/{geofence}', [App\Http\Controllers\Admin\GeofenceController::class, 'destroy'])->name('geofences.destroy');
        Route::delete('geofences/site/{site}', [App\Http\Controllers\Admin\GeofenceController::class, 'destroyBySite'])->name('geofences.destroy-by-site');
        Route::post('geofences/{geofence}/test-point', [App\Http\Controllers\Admin\GeofenceController::class, 'testPoint'])->name('geofences.test-point');
        Route::get('sites/{site}/geofence/active', [App\Http\Controllers\Admin\GeofenceController::class, 'getActiveGeofence'])->name('geofences.active');
        Route::get('geofences/site/{site}/active', [App\Http\Controllers\Admin\GeofenceController::class, 'getActiveGeofence'])->name('geofences.site.active');

        // Shift Management routes - D-07 Wireframe with Calendar UI and WTR Compliance
        // Static/action routes must come before parameterised routes to avoid {shift} swallowing them.
        Route::get('shifts', [App\Http\Controllers\Admin\ShiftController::class, 'index'])->name('shifts.index');
        Route::post('shifts', [App\Http\Controllers\Admin\ShiftController::class, 'store'])->name('shifts.store');
        Route::post('shifts/check-wtr', [App\Http\Controllers\Admin\ShiftController::class, 'checkWTRCompliance'])->name('shifts.check-wtr');
        Route::get('shifts/{shift}/timeline', [App\Http\Controllers\Admin\ShiftController::class, 'timeline'])->name('shifts.timeline');
        Route::get('shifts/{shift}', [App\Http\Controllers\Admin\ShiftController::class, 'show'])->name('shifts.show');
        Route::put('shifts/{shift}', [App\Http\Controllers\Admin\ShiftController::class, 'update'])->name('shifts.update');
        Route::patch('shifts/{shift}/cancel', [App\Http\Controllers\Admin\ShiftController::class, 'cancel'])->name('shifts.cancel');
        Route::patch('shifts/{shift}/resolve', [App\Http\Controllers\Admin\ShiftController::class, 'resolve'])->name('shifts.resolve');
        Route::patch('shifts/{shift}/early-end/approve', [App\Http\Controllers\Admin\ShiftController::class, 'approveEarlyEnd'])->name('shifts.early-end.approve');
        Route::patch('shifts/{shift}/early-end/reject', [App\Http\Controllers\Admin\ShiftController::class, 'rejectEarlyEnd'])->name('shifts.early-end.reject');

        // Photo verification (Phase 4): manually request a live photo from the
        // guard on an active shift, and view stored evidence (admin-gated; the
        // file is never publicly served).
        Route::post('shifts/{shift}/request-photo', [App\Http\Controllers\Admin\ShiftController::class, 'requestPhoto'])->name('shifts.request-photo');
        // Wakefulness verification (Phase 5): manually challenge the guard on an
        // active shift with a live code-challenge (pushed to the app, same as a
        // scheduled challenge). No admin review — the result is auto CONFIRMED/FAILED.
        Route::post('shifts/{shift}/request-wakefulness', [App\Http\Controllers\Admin\ShiftController::class, 'requestWakefulness'])->name('shifts.request-wakefulness');
        // One-time SSO access link: mint a single-use, 1-hour link the guard can
        // use to sign in to this shift without a password (still passes all login
        // gates at redemption). Returned to the drawer, which copies it.
        Route::post('shifts/{shift}/access-link', [App\Http\Controllers\Admin\ShiftController::class, 'generateAccessLink'])->name('shifts.access-link');
        Route::get('photos/{evidence}/view', [App\Http\Controllers\Admin\ShiftController::class, 'viewPhoto'])->name('photos.view');
        // Record an admin's manual review (approve/reject) of a stored photo. The
        // decision feeds the compliance summary and is pushed/polled to the guard.
        Route::post('photos/{evidence}/review', [App\Http\Controllers\Admin\ShiftController::class, 'reviewPhoto'])->name('photos.review');

        // Reports & Exports (Phase 8 · D-09). Static/action routes before the
        // {report} parameter so `generate` isn't captured as a report id. Files
        // live on the private `reports` disk and are streamed only through the
        // admin-gated download route — no path is ever exposed to a view.
        Route::get('reports', [App\Http\Controllers\Admin\ReportController::class, 'index'])->name('reports.index');
        Route::post('reports/generate', [App\Http\Controllers\Admin\ReportController::class, 'generate'])->name('reports.generate');
        // Bulk delete (POST, static) before the {report} wildcard so it is not
        // read as a report id.
        Route::post('reports/bulk-delete', [App\Http\Controllers\Admin\ReportController::class, 'bulkDestroy'])->name('reports.bulk-delete');
        // Specific action routes before the bare {report} page route so the id
        // wildcard cannot swallow them.
        Route::get('reports/{report}/download', [App\Http\Controllers\Admin\ReportController::class, 'download'])->name('reports.download');
        // The on-screen report page (the "slug" page) generation redirects to.
        Route::get('reports/{report}', [App\Http\Controllers\Admin\ReportController::class, 'show'])->name('reports.show');
        // Delete a single persisted report (row + private file).
        Route::delete('reports/{report}', [App\Http\Controllers\Admin\ReportController::class, 'destroy'])->name('reports.destroy');
        // One-click Shift Welfare Report from the Shift Detail page (Component D).
        Route::post('shifts/{shift}/report', [App\Http\Controllers\Admin\ReportController::class, 'shiftReport'])->name('shifts.report');

        // Backup management (admin-only): list monthly evidence archives for the
        // sidebar Backup modal and download a month's ZIP. Generation is dynamic
        // and read-only over the evidence store; never publicly accessible.
        Route::get('backups', [App\Http\Controllers\Admin\BackupController::class, 'index'])->name('backups.index');
        Route::get('backups/{month}/download', [App\Http\Controllers\Admin\BackupController::class, 'download'])
            ->where('month', '\d{4}-\d{2}')->name('backups.download');

        // API status endpoint
        Route::get('status', function () {
            return response()->json([
                'status' => 'IronLock Admin API Ready',
                'version' => '1.0.0',
                'architecture' => 'Laravel + MySQL (Firebase FCM push only)',
                'timestamp' => now()->toISOString(),
                'authenticated_admin' => session('admin_id'),
            ]);
        });
    });
});
