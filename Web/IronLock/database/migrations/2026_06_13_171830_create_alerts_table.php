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
        Schema::create('alerts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('shift_id', 36);
            $table->char('guard_id', 36);
            $table->string('type', 50); // ZONE_EXIT, GUARD_UNRESPONSIVE, etc.
            $table->enum('severity', ['CRITICAL', 'WARNING']);
            $table->enum('status', ['OPEN', 'ACKNOWLEDGED'])->default('OPEN');
            $table->string('title');
            $table->text('description')->nullable();
            $table->timestamp('raised_at')->useCurrent();
            $table->char('acknowledged_by', 36)->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->text('acknowledgment_note')->nullable();
            $table->char('resolved_by', 36)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('shift_id', 'idx_alerts_shift_id');
            $table->index('guard_id', 'idx_alerts_guard_id');
            $table->index('status', 'idx_alerts_status');
            $table->index('severity', 'idx_alerts_severity');
            $table->index('type', 'idx_alerts_type');
            $table->index('raised_at', 'idx_alerts_raised_at');

            // Foreign Keys
            $table->foreign('shift_id', 'fk_alerts_shift')->references('id')->on('shifts');
            $table->foreign('guard_id', 'fk_alerts_guard')->references('id')->on('guards');
            $table->foreign('acknowledged_by', 'fk_alerts_acknowledged_by')->references('id')->on('admins');
            $table->foreign('resolved_by', 'fk_alerts_resolved_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};