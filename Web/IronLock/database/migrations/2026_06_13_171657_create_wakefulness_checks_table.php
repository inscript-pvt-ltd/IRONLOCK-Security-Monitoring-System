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
        // Wakefulness Checks — TOTP-based challenges
        Schema::create('wakefulness_checks', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('shift_id', 36);
            $table->char('guard_id', 36);
            $table->string('challenge_code', 4);
            $table->string('submitted_code', 4)->nullable();
            $table->integer('totp_window_reference')->nullable(); // 30-second TOTP windows
            $table->dateTime('scheduled_at');
            $table->timestamp('responded_at')->nullable();
            $table->decimal('response_time_seconds', 4, 2)->nullable();
            $table->timestamp('server_received_at')->nullable();
            $table->enum('result', ['CONFIRMED', 'FAILED']);
            $table->boolean('is_offline')->default(false);
            $table->enum('online_or_offline', ['ONLINE', 'OFFLINE']);
            $table->timestamps();

            // Indexes
            $table->index('shift_id', 'idx_wakefulness_shift_id');
            $table->index('guard_id', 'idx_wakefulness_guard_id');
            $table->index('scheduled_at', 'idx_wakefulness_scheduled_at');
            $table->index('result', 'idx_wakefulness_result');

            // Foreign Keys
            $table->foreign('shift_id', 'fk_wakefulness_shift')->references('id')->on('shifts');
            $table->foreign('guard_id', 'fk_wakefulness_guard')->references('id')->on('guards');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wakefulness_checks');
    }
};