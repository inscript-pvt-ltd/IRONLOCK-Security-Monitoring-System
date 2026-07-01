<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen-snapshot payload for generated reports (Phase 8 · on-screen report page).
 *
 * A report is now viewable as an on-screen page and downloadable as PDF/CSV, all
 * from the exact data computed at generation time. `payload` stores that computed
 * snapshot (pure JSON — no live re-query) so the page and every download reflect
 * the same frozen moment: a true audit/compliance artifact. `file_path` keeps the
 * on-disk PDF (unchanged); the snapshot is the source of truth for the HTML view
 * and on-demand CSV.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->longText('payload')->nullable()->after('file_path');
        });
    }

    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
