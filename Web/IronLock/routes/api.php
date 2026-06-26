<?php

use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\ShiftController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
|--------------------------------------------------------------------------
| Mobile API (guard app) — v1
|--------------------------------------------------------------------------
| Contract: Details/Important/MOBILE_API_INTEGRATION.md
| Public routes: status, auth/login, auth/refresh.
| Everything else sits behind the `guard.auth` JWT middleware.
*/
Route::prefix('mobile/v1')->middleware(\App\Http\Middleware\LogMobileApiActivity::class)->group(function () { //middleware is temp log.
    // Health check — no token required (contract §2). Bare object, not the
    // success envelope, so the app can ping it before auth is wired.
    Route::get('status', function () {
        return response()->json([
            'status' => 'IronLock Mobile API Ready',
            'version' => '1.0.0',
            'timestamp' => now()->toISOString(),
        ]);
    });

    // Public authentication endpoints (throttled).
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:20,1');
    // One-time Shift Access Link (SSO) redemption — passwordless, but runs the
    // same login gates as auth/login. Throttled like login to blunt token guessing.
    Route::post('auth/shift-access', [AuthController::class, 'shiftAccess'])->middleware('throttle:10,1');

    // Protected — require a valid guard access token.
    Route::middleware('guard.auth')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('shifts/current', [ShiftController::class, 'current']);
        Route::post('shifts/{id}/start', [ShiftController::class, 'start']);
        Route::post('shifts/{id}/early-end-request', [ShiftController::class, 'earlyEndRequest']);
        Route::post('shifts/{id}/end', [ShiftController::class, 'end']);

        // GPS live tracking (Phase 3.3, contract §6.1). Batch-capable ping
        // endpoint; server runs the geofence check and UPSERTs the live row.
        Route::post('shifts/{id}/locations', [\App\Http\Controllers\Mobile\GPSController::class, 'ping']);

        // Photo verification (Phase 4, contract §6.3–6.5). Nonce prefetch for
        // the offline pool, pending-request discovery, signed photo upload, and
        // push-token registration.
        Route::post('shifts/{id}/nonces/prefetch', [\App\Http\Controllers\Mobile\NonceController::class, 'prefetch']);
        Route::get('shifts/{id}/photos/pending', [\App\Http\Controllers\Mobile\PhotoController::class, 'pending']);
        Route::post('shifts/{id}/photos', [\App\Http\Controllers\Mobile\PhotoController::class, 'upload']);
        // Admin review outcomes (approve/reject) for this shift's photos. Also
        // pushed best-effort via FCM PHOTO_REVIEWED; this poll is the fallback.
        Route::get('shifts/{id}/photos/reviews', [\App\Http\Controllers\Mobile\PhotoController::class, 'reviews']);
        Route::post('devices/push-token', [\App\Http\Controllers\Mobile\DeviceController::class, 'registerPushToken']);

        // Wakefulness verification (Phase 5, contract §6.2). Answer a dispatched
        // code-challenge; the server is the sole authority on pass/fail.
        Route::post('wakefulness/{checkId}/respond', [\App\Http\Controllers\Mobile\WakefulnessController::class, 'respond']);

        // Push-delivery receipt (Phase 6). The app confirms an online challenge
        // push arrived so the sweep won't false-alarm a dropped push.
        Route::post('wakefulness/{checkId}/received', [\App\Http\Controllers\Mobile\WakefulnessController::class, 'received']);
    });
});
