<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-site verification settings (Settings page).
 *
 * Each site can independently switch photo verification and wakefulness
 * checking on or off, and tune the random min/max spacing of each schedule.
 *
 * Toggles default to ON so every existing site keeps today's behaviour. The
 * gap columns are NULLABLE on purpose: NULL means "inherit the global default"
 * (config('ironlock.photo_min_gap_minutes') etc.), so nothing needs a backfill
 * and the config file stays the single source of the fallback values.
 *
 * When a toggle is OFF the shift's schedule builder returns an empty array at
 * start, which disables that check on BOTH paths at once — the online
 * dispatcher skips an empty schedule, and the mobile start payload sends an
 * empty schedule so the app fires nothing offline either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('photo_verification_enabled')->default(true)->after('grace_period_minutes');
            $table->boolean('wakefulness_enabled')->default(true)->after('photo_verification_enabled');
            $table->unsignedSmallInteger('photo_min_gap_minutes')->nullable()->after('wakefulness_enabled');
            $table->unsignedSmallInteger('photo_max_gap_minutes')->nullable()->after('photo_min_gap_minutes');
            $table->unsignedSmallInteger('wakefulness_min_gap_minutes')->nullable()->after('photo_max_gap_minutes');
            $table->unsignedSmallInteger('wakefulness_max_gap_minutes')->nullable()->after('wakefulness_min_gap_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn([
                'photo_verification_enabled',
                'wakefulness_enabled',
                'photo_min_gap_minutes',
                'photo_max_gap_minutes',
                'wakefulness_min_gap_minutes',
                'wakefulness_max_gap_minutes',
            ]);
        });
    }
};
