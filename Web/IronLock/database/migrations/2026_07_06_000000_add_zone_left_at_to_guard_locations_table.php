<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track WHEN the guard's current out-of-zone excursion began.
     *
     * guard_locations keeps one mutable row per guard (no history), so a delayed
     * CheckZoneExitJob had no way to tell whether the excursion it was created for
     * is still the live one. `zone_left_at` is the excursion anchor: set on an
     * INSIDE->OUTSIDE transition, preserved across consecutive OUTSIDE pings, and
     * cleared (null) on the return INSIDE. The job carries the anchor it was
     * dispatched for and only raises if the row's anchor still matches — so a
     * stale job from an earlier exit (guard left, returned, left again) no longer
     * fires early against the wrong excursion. Doubles as the dashboard's
     * authoritative "outside since" time.
     */
    public function up(): void
    {
        Schema::table('guard_locations', function (Blueprint $table) {
            $table->timestamp('zone_left_at')->nullable()->after('zone_status');
        });
    }

    public function down(): void
    {
        Schema::table('guard_locations', function (Blueprint $table) {
            $table->dropColumn('zone_left_at');
        });
    }
};
