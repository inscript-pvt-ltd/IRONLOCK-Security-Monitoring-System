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
        Schema::create('photo_requests', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('shift_id', 36);
            $table->char('guard_id', 36);
            $table->char('nonce_id', 36)->nullable();
            $table->char('requested_by', 36)->nullable(); // Admin or NULL for scheduler
            $table->enum('request_type', ['manual', 'scheduled']);
            $table->timestamp('nonce_issued_at')->nullable();
            $table->enum('status', ['PENDING', 'FULFILLED', 'TIMEOUT', 'ANOMALY'])->default('PENDING');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('server_received_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('shift_id', 'idx_photo_requests_shift_id');
            $table->index('guard_id', 'idx_photo_requests_guard_id');
            $table->index('status', 'idx_photo_requests_status');
            $table->index('requested_at', 'idx_photo_requests_requested_at');

            // Foreign Keys
            $table->foreign('shift_id', 'fk_photo_requests_shift')->references('id')->on('shifts');
            $table->foreign('guard_id', 'fk_photo_requests_guard')->references('id')->on('guards');
            $table->foreign('nonce_id', 'fk_photo_requests_nonce')->references('id')->on('nonces');
            $table->foreign('requested_by', 'fk_photo_requests_requested_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_requests');
    }
};