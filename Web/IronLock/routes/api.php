<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

// Mobile API routes
Route::prefix('mobile/v1')->group(function () {
    Route::get('status', function () {
        return response()->json([
            'status' => 'IronLock Mobile API Ready',
            'version' => '1.0.0',
            'database' => 'MySQL 8.0',
            'timestamp' => now()->toISOString(),
        ]);
    });
});