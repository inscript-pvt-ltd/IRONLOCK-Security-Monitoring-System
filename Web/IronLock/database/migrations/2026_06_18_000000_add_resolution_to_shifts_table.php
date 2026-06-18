<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Supervisor-resolution flags for the shifts schedule.
 *
 * A shift needs supervisor attention (red ⚠ on the dashboard) in three cases:
 *  - never checked in within the window            → status = missed (checked_in_at null)
 *  - checked in but never started before it closed → status = missed (checked_in_at set)
 *  - ended before the scheduled end time           → status = completed + ended_early = true
 *
 * Rather than add more enum values, we keep the existing statuses and add:
 *  - ended_early : the guard ended materially before scheduled_end (set in Shift::end()).
 *  - resolved_at : when a supervisor resolved the flag — once set, the ⚠ clears.
 *
 * A shift "needs resolution" when resolved_at IS NULL AND (status = missed OR ended_early).
 * markMissed() clears resolved_at so a re-missed shift is flagged afresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->boolean('ended_early')->default(false)->after('attendance_note');
            $table->timestamp('resolved_at')->nullable()->after('ended_early');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn(['ended_early', 'resolved_at']);
        });
    }
};
