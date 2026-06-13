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
        Schema::create('guard_sessions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('guard_id', 36);
            $table->string('access_token_hash');
            $table->string('refresh_token_hash');
            $table->dateTime('expires_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('guard_id', 'idx_guard_sessions_guard_id');
            $table->index('expires_at', 'idx_guard_sessions_expires_at');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_guard_sessions_guard')->references('id')->on('guards')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guard_sessions');
    }
};