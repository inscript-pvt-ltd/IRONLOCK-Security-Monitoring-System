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
        // Shift Events — Immutable audit trail
        Schema::create('shift_events', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('shift_id', 36);
            $table->char('guard_id', 36);
            $table->string('event_type', 50);
            $table->json('metadata')->nullable();
            $table->dateTime('recorded_at'); // Device timestamp (audit only)
            $table->timestamp('server_received_at')->useCurrent(); // Authoritative timestamp
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('shift_id', 'idx_shift_events_shift_id');
            $table->index('guard_id', 'idx_shift_events_guard_id');
            $table->index('event_type', 'idx_shift_events_type');
            $table->index('server_received_at', 'idx_shift_events_server_time');

            // Foreign Keys
            $table->foreign('shift_id', 'fk_shift_events_shift')->references('id')->on('shifts');
            $table->foreign('guard_id', 'fk_shift_events_guard')->references('id')->on('guards');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shift_events');
    }
};