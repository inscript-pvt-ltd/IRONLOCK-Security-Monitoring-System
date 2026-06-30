<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 7 (Option A) — Offline photo schedule.
 *
 * shifts.photo_schedule (JSON) — the randomised verification-photo schedule
 * provisioned at shift start, analogous to wakefulness_schedule. The online
 * dispatcher (photos:dispatch-scheduled) fires an ONLINE PHOTO_REQUEST at each
 * due mark; while the device is offline the app fires the camera at the SAME
 * marks against a pre-fetched OFFLINE_POOL nonce. One source of truth so the
 * cadence is identical whether or not the guard is connected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->json('photo_schedule')->nullable()->after('wakefulness_schedule');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('photo_schedule');
        });
    }
};
