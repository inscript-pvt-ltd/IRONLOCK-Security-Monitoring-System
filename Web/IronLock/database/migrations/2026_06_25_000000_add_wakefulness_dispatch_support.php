<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 — Wakefulness Verification support.
 *
 *  1. shifts.wakefulness_schedule (JSON) — the randomised 30–45 min challenge
 *     schedule provisioned at shift start (spec §9.4.1). The dispatcher fires
 *     the online FCM challenge at each due mark; the app fires the matching
 *     offline local notification at the same times.
 *
 *  2. wakefulness_checks.result made NULLABLE — a freshly dispatched check is
 *     PENDING (result = null) until the guard responds or the timeout sweep
 *     resolves it to CONFIRMED / FAILED. The original enum was NOT NULL with no
 *     default, leaving no way to represent the awaiting-response state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->json('wakefulness_schedule')->nullable()->after('totp_seed');
        });

        // The original totp_seed was VARCHAR(255), but the 'encrypted' cast wraps
        // the Base32 seed in a base64 JSON envelope (iv/value/mac) that overflows
        // 255 chars. Widen to TEXT so the ciphertext always fits.
        DB::statement("ALTER TABLE `shifts` MODIFY `totp_seed` TEXT NULL");

        // MariaDB: change the enum to allow NULL without needing doctrine/dbal.
        DB::statement("ALTER TABLE `wakefulness_checks` MODIFY `result` ENUM('CONFIRMED','FAILED') NULL");
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('wakefulness_schedule');
        });

        DB::statement("ALTER TABLE `shifts` MODIFY `totp_seed` VARCHAR(255) NULL");

        // Restore NOT NULL. Any PENDING (null) rows would block this, so fail
        // loud rather than silently coercing them — they must be resolved first.
        DB::statement("ALTER TABLE `wakefulness_checks` MODIFY `result` ENUM('CONFIRMED','FAILED') NOT NULL");
    }
};
