<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shift Access Links — one-time SSO sign-in for a guard's shift.
     *
     * A supervisor can mint a cryptographically-secure single-use link bound to
     * a specific shift + guard. Only the SHA-256 hash of the token is stored, so
     * a DB leak never exposes a usable link. The link is valid for a short TTL
     * (config ironlock.shift_access_link_ttl_minutes, default 60), is consumed on
     * first successful redemption (used_at), and an older unused link is
     * superseded when a new one is generated (invalidated_at). Redemption still
     * goes through the exact same login-window rules as normal guard login — the
     * link only removes the password step, it never bypasses any other gate.
     */
    public function up(): void
    {
        Schema::create('shift_access_links', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('shift_id', 36);
            $table->char('guard_id', 36);
            // SHA-256 hex of the raw token — the raw token lives only in the URL.
            $table->char('token_hash', 64)->unique('uq_shift_access_links_token');
            $table->char('created_by', 36)->nullable(); // admin who generated it
            $table->timestamp('used_at')->nullable();         // single-use marker
            $table->timestamp('invalidated_at')->nullable();  // superseded by a newer link
            // Always set in PHP (generated_at + TTL); nullable only to satisfy
            // MariaDB's TIMESTAMP-default rule. A null expiry is treated as
            // already-expired at redemption, so it can never widen access.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('shift_id', 'idx_shift_access_links_shift_id');
            $table->index('guard_id', 'idx_shift_access_links_guard_id');

            // Foreign Keys — no cascade on shift_id: the shift-cancel flow clears
            // child rows explicitly (ShiftController::cancel) before deleting the
            // shift, matching the other shift-scoped tables.
            $table->foreign('shift_id', 'fk_shift_access_links_shift')->references('id')->on('shifts');
            $table->foreign('guard_id', 'fk_shift_access_links_guard')->references('id')->on('guards');
            $table->foreign('created_by', 'fk_shift_access_links_admin')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_access_links');
    }
};
