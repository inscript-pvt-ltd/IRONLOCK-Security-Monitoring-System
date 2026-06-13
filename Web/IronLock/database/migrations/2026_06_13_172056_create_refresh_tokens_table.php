<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Refresh Tokens — rotating tokens for both guards and admins
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('guard_id', 36)->nullable();
            $table->char('admin_id', 36)->nullable();
            $table->string('token_hash');
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('guard_id', 'idx_refresh_tokens_guard');
            $table->index('admin_id', 'idx_refresh_tokens_admin');
            $table->index('expires_at', 'idx_refresh_tokens_expires');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_refresh_tokens_guard')->references('id')->on('guards');
            $table->foreign('admin_id', 'fk_refresh_tokens_admin')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
    }
};