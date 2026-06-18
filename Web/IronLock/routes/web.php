<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Redirect root to admin login
Route::get('/', function () {
    return redirect()->route('admin.login');
});

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

        // Guard Management routes - D-05 Wireframe with Drawer UI
        Route::get('guards', [App\Http\Controllers\Admin\GuardController::class, 'index'])->name('guards.index');
        Route::get('guards/list', [App\Http\Controllers\Admin\GuardController::class, 'list'])->name('guards.list'); // Must be before parameterized routes
        Route::post('guards', [App\Http\Controllers\Admin\GuardController::class, 'store'])->name('guards.store');
        Route::get('guards/{guard}/shifts', [App\Http\Controllers\Admin\GuardController::class, 'recentShifts'])->name('guards.shifts'); // AJAX: completed shifts for drawer
        Route::get('guards/{guard}', [App\Http\Controllers\Admin\GuardController::class, 'show'])->name('guards.show'); // AJAX endpoint
        Route::put('guards/{guard}', [App\Http\Controllers\Admin\GuardController::class, 'update'])->name('guards.update');
        Route::patch('guards/{guard}/toggle-status', [App\Http\Controllers\Admin\GuardController::class, 'toggleStatus'])->name('guards.toggle-status');
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

        // Debug endpoint for testing encoding
        Route::post('debug/geofence', function (Request $request) {
            try {
                \Log::info('Debug geofence request', [
                    'all' => $request->all(),
                    'raw' => $request->getContent(),
                    'headers' => $request->headers->all()
                ]);

                return response()->json([
                    'success' => true,
                    'received' => $request->all(),
                    'coordinates_count' => count($request->coordinates ?? []),
                    'encoding' => mb_detect_encoding($request->getContent())
                ]);
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'error' => $e->getMessage(),
                    'type' => get_class($e)
                ]);
            }
        });
    });
});
