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
        // Guard Activity Logs — Audit trail for guard management actions
        Schema::create('guard_activity_logs', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('guard_id', 36);
            $table->string('action', 100); // 'created', 'updated', 'activated', etc.
            $table->text('description')->nullable();
            $table->char('performed_by', 36)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('guard_id', 'idx_activity_logs_guard');
            $table->index('action', 'idx_activity_logs_action');
            $table->index('performed_by', 'idx_activity_logs_performed_by');
            $table->index('created_at', 'idx_activity_logs_created_at');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_activity_logs_guard')->references('id')->on('guards');
            $table->foreign('performed_by', 'fk_activity_logs_performed_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guard_activity_logs');
    }
};