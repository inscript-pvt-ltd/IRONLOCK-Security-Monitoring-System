<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Monthly evidence backup archives.
     *
     * One row per calendar month that has photo evidence. A completed month is
     * downloadable as a ZIP for a retention window (config
     * ironlock.backup_retention_days, default 30) measured from the month's end;
     * after that the scheduled cleanup removes the row and any cached ZIP. This
     * table tracks ONLY the generated archive — it never references or deletes the
     * original immutable photo_evidences rows. zip_path is the cached archive on
     * the private `backups` disk (generated lazily on first download of a
     * completed month); expires_at is null while the month is still current.
     */
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('month_key', 7)->unique('uq_backups_month'); // "YYYY-MM"
            $table->string('label', 30);                                // "June 2026"
            $table->date('period_start');                               // first day of month
            $table->date('period_end');                                 // last day of month
            $table->timestamp('expires_at')->nullable();               // period_end + retention
            $table->string('zip_path', 500)->nullable();               // cached archive on `backups` disk
            $table->timestamp('zip_generated_at')->nullable();
            $table->timestamps();

            $table->index('expires_at', 'idx_backups_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
