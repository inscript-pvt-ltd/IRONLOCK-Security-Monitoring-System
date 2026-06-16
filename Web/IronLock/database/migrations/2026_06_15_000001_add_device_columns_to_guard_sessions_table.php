<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AuthService::createGuardSession() records the calling device on each guard
 * session for audit and "new device" detection (see the device object in
 * MOBILE_API_INTEGRATION.md §4.4), but the original guard_sessions migration
 * never created the columns. This adds them so login can persist device info.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guard_sessions', function (Blueprint $table) {
            $table->string('device_identifier')->nullable()->after('refresh_token_hash');
            $table->string('device_name')->nullable()->after('device_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('guard_sessions', function (Blueprint $table) {
            $table->dropColumn(['device_identifier', 'device_name']);
        });
    }
};
