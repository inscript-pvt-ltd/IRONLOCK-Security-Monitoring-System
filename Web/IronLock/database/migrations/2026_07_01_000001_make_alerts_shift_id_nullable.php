<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Allow alerts to exist without a shift (Phase 8).
 *
 * Every alert to date was raised during a shift (zone exit, unresponsive
 * guard, photo timeout), so shift_id was NOT NULL. Phase 8 introduces the SIA
 * licence-expiry alert, which is a guard-level compliance concern with no shift
 * context — so shift_id becomes nullable. The FK to shifts is retained (it
 * simply permits NULL now); the Alert Feed already reads the shift relation
 * with optional chaining, so a null shift renders as "—" with no UI change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->char('shift_id', 36)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Reverting to NOT NULL would fail if any guard-level (shift-less) alerts
        // exist; clear those first so the column can be tightened again.
        \Illuminate\Support\Facades\DB::table('alerts')->whereNull('shift_id')->delete();

        Schema::table('alerts', function (Blueprint $table) {
            $table->char('shift_id', 36)->nullable(false)->change();
        });
    }
};
