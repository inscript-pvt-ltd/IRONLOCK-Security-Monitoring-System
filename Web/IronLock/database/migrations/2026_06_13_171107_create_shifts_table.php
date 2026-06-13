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
        Schema::create('shifts', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('guard_id', 36);
            $table->char('site_id', 36);
            $table->char('geofence_id', 36);
            $table->dateTime('scheduled_start');
            $table->dateTime('scheduled_end');
            $table->timestamp('actual_start')->nullable();
            $table->timestamp('actual_end')->nullable();
            $table->string('totp_seed')->nullable(); // Encrypted TOTP seed
            $table->enum('status', ['scheduled', 'active', 'completed', 'cancelled'])->default('scheduled');
            $table->string('started_by', 50)->nullable();
            $table->string('ended_by', 50)->nullable();
            $table->text('override_reason')->nullable();
            $table->json('compliance_summary')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->timestamps();

            // Indexes
            $table->index('guard_id', 'idx_shifts_guard_id');
            $table->index('site_id', 'idx_shifts_site_id');
            $table->index('geofence_id', 'idx_shifts_geofence_id');
            $table->index('status', 'idx_shifts_status');
            $table->index('scheduled_start', 'idx_shifts_scheduled_start');
            $table->index(['scheduled_start', 'scheduled_end'], 'idx_shifts_date_range');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_shifts_guard')->references('id')->on('guards');
            $table->foreign('site_id', 'fk_shifts_site')->references('id')->on('sites');
            $table->foreign('geofence_id', 'fk_shifts_geofence')->references('id')->on('geofences');
            $table->foreign('created_by', 'fk_shifts_created_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }
};