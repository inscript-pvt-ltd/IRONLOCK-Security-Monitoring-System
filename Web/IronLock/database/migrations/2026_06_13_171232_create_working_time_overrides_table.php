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
        Schema::create('working_time_overrides', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('shift_id', 36);
            $table->enum('override_type', ['duration_12hr', 'rest_period_11hr']);
            $table->text('justification');
            $table->char('approved_by', 36);
            $table->dateTime('approved_at');
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index('shift_id', 'idx_wtr_shift_id');
            $table->index('override_type', 'idx_wtr_type');

            // Foreign Keys
            $table->foreign('shift_id', 'fk_wtr_shift')->references('id')->on('shifts')->onDelete('cascade');
            $table->foreign('approved_by', 'fk_wtr_approved_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('working_time_overrides');
    }
};