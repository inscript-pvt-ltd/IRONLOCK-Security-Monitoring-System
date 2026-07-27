<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record WHY a photo upload was rejected.
     *
     * A request reaches ANOMALY only from the upload endpoint — the guard did
     * send a photo and the server threw it away (bad HMAC, expired nonce,
     * timeline anomaly, nonce already used). Until now the reason existed only
     * in the 422 body handed back to the app: nothing persisted it, so an admin
     * looking at an ANOMALY row on the timeline or in a welfare report could not
     * tell a genuinely suspicious capture from a guard who simply answered a few
     * seconds after the 90s window closed. Both read identically.
     *
     * Nullable and additive: existing rows stay NULL, which every read path
     * treats as "no reason recorded" and renders exactly as it does today.
     */
    public function up(): void
    {
        Schema::table('photo_requests', function (Blueprint $table) {
            $table->string('rejection_reason', 64)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('photo_requests', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
