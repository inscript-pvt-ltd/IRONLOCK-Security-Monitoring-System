<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Distinguish how a wakefulness check was triggered: a randomised scheduled
 * challenge vs. one a supervisor raised manually from the dashboard (mirrors
 * photo_requests.request_type). Defaults to 'scheduled' so existing rows and the
 * automatic dispatcher are unaffected; the manual admin path sets 'manual'.
 */
return new class extends Migration
{
    public function up(): void
    {
        // MariaDB: add the enum via raw SQL (no doctrine/dbal needed), defaulting
        // to 'scheduled' to backfill existing rows and keep the dispatcher path
        // unchanged.
        DB::statement("ALTER TABLE `wakefulness_checks` ADD `request_type` ENUM('manual','scheduled') NOT NULL DEFAULT 'scheduled' AFTER `online_or_offline`");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `wakefulness_checks` DROP COLUMN `request_type`');
    }
};
