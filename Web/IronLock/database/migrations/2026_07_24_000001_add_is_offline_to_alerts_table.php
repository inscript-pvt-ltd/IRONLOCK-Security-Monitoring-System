<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Flag alerts that originated from an OFFLINE flush (a wakefulness code mismatch
 * or a suspicious photo hard-reject replayed on reconnect), so the Alert Feed
 * and dashboard can surface them under a distinct "Offline" filter. These are
 * genuine failures the supervisor must still see, but they happened during a
 * connectivity gap and were only materialised when the device came back online —
 * the flag lets the UI tell them apart from live, in-the-moment alerts.
 *
 * Defaults to false so every existing and future live alert is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->boolean('is_offline')->default(false)->after('severity');
            $table->index('is_offline', 'idx_alerts_is_offline');
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropIndex('idx_alerts_is_offline');
            $table->dropColumn('is_offline');
        });
    }
};
