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
        // Login Attempts — Security audit trail for both admins and guards
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('guard_id', 36)->nullable();
            $table->char('admin_id', 36)->nullable();
            $table->string('username_or_email')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('successful');
            $table->timestamp('attempted_at')->useCurrent();

            // Indexes
            $table->index('guard_id', 'idx_login_attempts_guard');
            $table->index('admin_id', 'idx_login_attempts_admin');
            $table->index('ip_address', 'idx_login_attempts_ip');
            $table->index('attempted_at', 'idx_login_attempts_time');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_login_attempts_guard')->references('id')->on('guards');
            $table->foreign('admin_id', 'fk_login_attempts_admin')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_attempts');
    }
};