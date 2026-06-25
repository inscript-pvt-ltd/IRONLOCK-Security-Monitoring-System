<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 (Photo Verification) — per-guard secrets the photo pipeline needs.
 *
 *  - hmac_secret: the shared symmetric key the guard's app uses to sign each
 *    photo-upload payload (spec §12.5). The server recomputes the HMAC with
 *    this same key to verify integrity, so it is stored raw (NOT hashed) and
 *    hidden from serialization. Provisioned at login.
 *  - push_token / push_token_platform: the device FCM/APNs token used to
 *    deliver photo requests (best-effort; FcmService lands in Phase 6).
 *
 * Neither the canonical schema (database_schema_mysql_corrected.sql) nor the
 * ERD carried these columns, so they are genuinely new. The canonical SQL file
 * is updated alongside this migration to stay the source of truth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->string('hmac_secret')->nullable()->after('active_session_token_id');
            $table->string('push_token')->nullable()->after('hmac_secret');
            $table->enum('push_token_platform', ['ios', 'android'])->nullable()->after('push_token');
        });
    }

    public function down(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->dropColumn(['hmac_secret', 'push_token', 'push_token_platform']);
        });
    }
};
