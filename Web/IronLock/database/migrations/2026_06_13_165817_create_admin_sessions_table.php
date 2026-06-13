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
        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('admin_id', 36);
            $table->string('access_token_hash');
            $table->string('refresh_token_hash');
            $table->dateTime('expires_at');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('admin_id', 'idx_admin_sessions_admin_id');
            $table->index('expires_at', 'idx_admin_sessions_expires_at');
            $table->index('access_token_hash', 'idx_admin_sessions_token_hash');

            // Foreign Keys
            $table->foreign('admin_id', 'fk_admin_sessions_admin')->references('id')->on('admins')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_sessions');
    }
};