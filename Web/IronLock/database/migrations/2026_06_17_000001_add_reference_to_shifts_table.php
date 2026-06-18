<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Human-readable shift reference (e.g. "SH-2847").
 *
 * This is a DISPLAY identifier only — the UUID `id` remains the primary key and
 * the contract key used by the mobile API (POST /shifts/{id}/start, etc.) and
 * every foreign key. `reference` is purely for admins to recognise a shift at a
 * glance (shown as "#SH-2847" in the dashboard / timeline).
 *
 * Stored nullable + UNIQUE: MySQL permits multiple NULLs under a unique index,
 * but the Shift model always assigns one on create, so in practice every row
 * carries a code. Existing rows are backfilled here in creation order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('reference', 20)->nullable()->unique()->after('id');
        });

        // Backfill existing shifts sequentially (SH-1001, SH-1002, …) in the
        // order they were created so the codes read chronologically.
        $start = 1001;
        $rows = DB::table('shifts')->orderBy('created_at')->orderBy('id')->pluck('id');

        foreach ($rows as $i => $id) {
            DB::table('shifts')
                ->where('id', $id)
                ->update(['reference' => 'SH-' . ($start + $i)]);
        }
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropUnique('shifts_reference_unique');
            $table->dropColumn('reference');
        });
    }
};
