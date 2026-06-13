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
        // Nonces — Cryptographic liveness proof for photo verification
        Schema::create('nonces', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('guard_id', 36);
            $table->char('shift_id', 36);
            $table->string('nonce_value')->unique();
            $table->timestamp('issued_at')->useCurrent();
            $table->dateTime('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->enum('type', ['ONLINE', 'OFFLINE_POOL']);
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('guard_id', 'idx_nonces_guard_id');
            $table->index('shift_id', 'idx_nonces_shift_id');
            $table->index('expires_at', 'idx_nonces_expires_at');
            $table->index('type', 'idx_nonces_type');
            $table->index('nonce_value', 'idx_nonces_value');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_nonces_guard')->references('id')->on('guards');
            $table->foreign('shift_id', 'fk_nonces_shift')->references('id')->on('shifts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nonces');
    }
};