<?php

use App\Http\Controllers\Mobile\AuthController;
use App\Http\Controllers\Mobile\ShiftController;
use App\Support\Mobile\MobileResponse;
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
Route::prefix('mobile/v1')->group(function () {
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

    // Protected — require a valid guard access token.
    Route::middleware('guard.auth')->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('shifts/current', [ShiftController::class, 'current']);
        Route::post('shifts/{id}/start', [ShiftController::class, 'start']);
        Route::post('shifts/{id}/end', [ShiftController::class, 'end']);

        // Reserved (Phase 3.3+) — shapes frozen in the contract (§6); these
        // return 501 until implemented so the app can stub against them.
        $notImplemented = fn () => MobileResponse::error(
            'NOT_IMPLEMENTED',
            'This feature is not available yet.',
            501
        );
        Route::post('shifts/{id}/locations', $notImplemented);
        Route::post('wakefulness/{checkId}/respond', $notImplemented);
        Route::post('shifts/{id}/photos', $notImplemented);
    });
});
