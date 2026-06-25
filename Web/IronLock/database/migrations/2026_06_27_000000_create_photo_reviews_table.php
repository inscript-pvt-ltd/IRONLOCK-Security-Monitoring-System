<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Admin photo review (Phase 4 follow-up).
     *
     * Records a supervisor's manual decision on a stored verification photo —
     * APPROVED or REJECTED, with an optional note. One review per evidence
     * (UNIQUE photo_evidence_id), inserted once and treated idempotently like an
     * alert acknowledgement; the evidence row itself stays immutable. shift_id /
     * guard_id are denormalised so the timeline summary and the guard's mobile
     * review poll can read without extra joins.
     */
    public function up(): void
    {
        Schema::create('photo_reviews', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('photo_evidence_id', 36)->unique('uq_photo_reviews_evidence');
            $table->char('photo_request_id', 36);
            $table->char('shift_id', 36);
            $table->char('guard_id', 36);
            $table->char('reviewed_by', 36)->nullable(); // admin who reviewed
            $table->enum('decision', ['APPROVED', 'REJECTED']);
            $table->text('note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('server_received_at')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('shift_id', 'idx_photo_reviews_shift_id');
            $table->index('guard_id', 'idx_photo_reviews_guard_id');
            $table->index('decision', 'idx_photo_reviews_decision');

            // Foreign Keys — cascade with the evidence/request they annotate.
            $table->foreign('photo_evidence_id', 'fk_photo_reviews_evidence')->references('id')->on('photo_evidences')->onDelete('cascade');
            $table->foreign('photo_request_id', 'fk_photo_reviews_request')->references('id')->on('photo_requests')->onDelete('cascade');
            $table->foreign('shift_id', 'fk_photo_reviews_shift')->references('id')->on('shifts');
            $table->foreign('guard_id', 'fk_photo_reviews_guard')->references('id')->on('guards');
            $table->foreign('reviewed_by', 'fk_photo_reviews_reviewed_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_reviews');
    }
};
