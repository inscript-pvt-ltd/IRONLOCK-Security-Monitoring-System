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
        // Reports — Generated PDF reports (compliance, shift summaries, etc.)
        Schema::create('reports', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('shift_id', 36)->nullable();
            $table->char('generated_by', 36)->nullable();
            $table->string('report_type', 100);
            $table->string('file_path', 500)->nullable(); // Path on Laravel server filesystem
            $table->timestamp('generated_at')->useCurrent();
            $table->timestamps();

            // Indexes
            $table->index('shift_id', 'idx_reports_shift');
            $table->index('generated_by', 'idx_reports_generated_by');
            $table->index('report_type', 'idx_reports_type');
            $table->index('generated_at', 'idx_reports_generated_at');

            // Foreign Keys
            $table->foreign('shift_id', 'fk_reports_shift')->references('id')->on('shifts');
            $table->foreign('generated_by', 'fk_reports_generated_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};