<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop the never-used alert resolution columns.
 *
 * `resolved_by` / `resolved_at` were defined when the alerts table was created
 * but no code path ever writes them — alert closure is represented entirely by
 * status = ACKNOWLEDGED (+ acknowledged_by / acknowledged_at / note). Removing
 * the dead columns (and the resolved_by → admins FK) so the schema matches the
 * actual resolution model.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->dropForeign('fk_alerts_resolved_by');
            $table->dropColumn(['resolved_by', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->char('resolved_by', 36)->nullable()->after('acknowledgment_note');
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
            $table->foreign('resolved_by', 'fk_alerts_resolved_by')->references('id')->on('admins');
        });
    }
};
