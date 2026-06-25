<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 — Push-delivery reliability.
 *
 * wakefulness_checks.delivered_at — the moment the guard's app confirmed it
 * received an ONLINE challenge push (via POST /wakefulness/{id}/received).
 *
 * This lets the timeout sweep tell apart a guard who got the challenge and
 * ignored it (delivered_at set → genuine CRITICAL GUARD_UNRESPONSIVE) from a
 * push that simply never arrived while the guard was online (delivered_at null →
 * a delivery failure, logged PUSH_UNDELIVERED, no supervisor paged). Closes the
 * Phase 5 "dropped push while online" false-alarm gap.
 *
 * Nullable + no backfill: existing checks predate the receipt signal and are
 * treated as "not confirmed delivered".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wakefulness_checks', function (Blueprint $table) {
            $table->timestamp('delivered_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('wakefulness_checks', function (Blueprint $table) {
            $table->dropColumn('delivered_at');
        });
    }
};
