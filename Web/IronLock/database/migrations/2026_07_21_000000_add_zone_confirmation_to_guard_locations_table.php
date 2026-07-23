<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Debounce GPS-jitter zone transitions with a confirmation window.
     *
     * A guard standing on the geofence boundary pings OUT/IN/OUT as GPS accuracy
     * drifts, which (a) littered the timeline with phantom ZONE_TRANSITION rows
     * and (b) silently reset the zone-exit grace clock on every flicker, so a
     * genuinely-outside guard could keep postponing their own alert.
     *
     * These two columns let recordLocation() hold a *candidate* status before
     * committing it: `zone_status` only flips (and a ZONE_TRANSITION is logged /
     * the grace job dispatched) once the raw reading has PERSISTED for the
     * configured confirm window (ironlock.zone_exit_confirm_seconds). A flicker
     * that reverts inside the window cancels the candidate — no transition, no
     * anchor reset.
     *
     *  - pending_zone_status: the candidate INSIDE/OUTSIDE awaiting confirmation,
     *    or null when the confirmed zone_status is stable.
     *  - pending_zone_since: when the candidate first appeared. For an OUTSIDE
     *    candidate this is the guard's real step-out moment, copied to
     *    zone_left_at on commit so the grace period is measured from the ACTUAL
     *    exit (the confirm window is absorbed into the grace, not added on top).
     *  - pending_is_shift_start: captured when an OUTSIDE candidate is born —
     *    true when the guard had no established same-shift position yet (their
     *    first fix of the shift is outside = "never reached post"). This must be
     *    remembered from candidate birth because by commit time (≥ confirm window
     *    later) a same-shift row always exists, so the original !prevSameShift
     *    signal is gone. Drives the SHIFT_START vs MID_SHIFT deadline choice.
     */
    public function up(): void
    {
        Schema::table('guard_locations', function (Blueprint $table) {
            $table->string('pending_zone_status')->nullable()->after('zone_left_at');
            $table->timestamp('pending_zone_since')->nullable()->after('pending_zone_status');
            $table->boolean('pending_is_shift_start')->nullable()->after('pending_zone_since');
        });
    }

    public function down(): void
    {
        Schema::table('guard_locations', function (Blueprint $table) {
            $table->dropColumn(['pending_zone_status', 'pending_zone_since', 'pending_is_shift_start']);
        });
    }
};
