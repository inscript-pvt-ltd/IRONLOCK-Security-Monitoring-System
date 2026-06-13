<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Admin routes
Route::prefix('admin')->group(function () {
    Route::get('/', function () {
        return response()->json([
            'status' => 'IronLock Admin API Ready',
            'version' => '1.0.0',
            'architecture' => 'Laravel + MySQL (Firebase FCM push only)',
            'timestamp' => now()->toISOString(),
        ]);
    });
});
