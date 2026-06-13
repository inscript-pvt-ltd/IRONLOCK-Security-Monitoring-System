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
        Schema::create('photo_evidences', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('photo_request_id', 36);
            $table->string('file_path', 500)->nullable(); // Path on Laravel server filesystem
            $table->string('sha256_hash', 64)->nullable();
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('ntp_timestamp_at_capture')->nullable();
            $table->timestamp('exif_timestamp')->nullable();
            $table->decimal('gps_latitude', 10, 8)->nullable();
            $table->decimal('gps_longitude', 11, 8)->nullable();
            $table->json('metadata')->nullable();
            $table->json('flags')->nullable(); // DELAYED_UPLOAD, CLOCK_MANIPULATION_SUSPECTED, etc.
            $table->timestamps();

            // Indexes
            $table->index('photo_request_id', 'idx_photo_evidences_request_id');
            $table->index('sha256_hash', 'idx_photo_evidences_sha256');
            $table->index('captured_at', 'idx_photo_evidences_captured_at');

            // Foreign Keys
            $table->foreign('photo_request_id', 'fk_photo_evidences_request')->references('id')->on('photo_requests')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('photo_evidences');
    }
};