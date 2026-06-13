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
        // Offline Sync Queue — stores encrypted offline events for later processing
        Schema::create('offline_sync_queue', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('guard_id', 36);
            $table->char('shift_id', 36)->nullable();
            $table->string('event_type', 50);
            $table->json('payload');
            $table->dateTime('device_timestamp');
            $table->timestamp('processed_at')->nullable();
            $table->integer('processing_attempts')->default(0);
            $table->timestamp('server_received_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('guard_id', 'idx_sync_queue_guard_id');
            $table->index('processed_at', 'idx_sync_queue_processed');
            $table->index('event_type', 'idx_sync_queue_event_type');
            $table->index('server_received_at', 'idx_sync_queue_server_time');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_sync_queue_guard')->references('id')->on('guards');
            $table->foreign('shift_id', 'fk_sync_queue_shift')->references('id')->on('shifts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('offline_sync_queue');
    }
};