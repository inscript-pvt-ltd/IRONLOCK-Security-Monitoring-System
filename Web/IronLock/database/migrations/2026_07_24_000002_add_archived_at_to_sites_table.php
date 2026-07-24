<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft-delete flag for sites. When an admin removes a site it is ARCHIVED, not
 * hard-deleted: the row stays in the DB so historical shifts (and their events,
 * alerts and reports) keep resolving the real site name and details, while the
 * site disappears from the admin list and can no longer be picked for new
 * shifts.
 *
 * Deliberately a plain nullable timestamp (not Laravel's SoftDeletes trait): a
 * global soft-delete scope would ALSO hide the site from `$shift->site`, which
 * would blank out historical shifts. This flag is filtered only in the listing
 * and picker queries, so live relations still see archived sites. Reversible.
 *
 * Nullable + defaults to null, so every existing site stays active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('status');
            $table->index('archived_at', 'idx_sites_archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex('idx_sites_archived_at');
            $table->dropColumn('archived_at');
        });
    }
};
