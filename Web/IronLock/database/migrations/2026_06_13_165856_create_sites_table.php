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
        Schema::create('sites', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->string('name');
            $table->text('address');
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('grace_period_minutes')->default(5);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->string('contact_person')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->text('instructions')->nullable();
            $table->char('created_by', 36)->nullable();
            $table->timestamps();

            // Indexes
            $table->index('status', 'idx_sites_status');
            $table->index('name', 'idx_sites_name');

            // Foreign Keys
            $table->foreign('created_by', 'fk_sites_created_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};