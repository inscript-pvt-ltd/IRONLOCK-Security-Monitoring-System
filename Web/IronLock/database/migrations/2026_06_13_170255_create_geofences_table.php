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
        Schema::create('geofences', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->char('site_id', 36);
            $table->string('name');
            $table->geometry('polygon', subtype: 'polygon', srid: 4326);  // Native MySQL spatial
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->char('created_by', 36)->nullable();
            $table->timestamps();

            // Spatial Index (MySQL spatial optimization)
            $table->spatialIndex('polygon', 'idx_geofences_polygon');

            // Regular Indexes
            $table->index('site_id', 'idx_geofences_site_id');
            $table->index('is_active', 'idx_geofences_active');

            // Foreign Keys
            $table->foreign('site_id', 'fk_geofences_site')->references('id')->on('sites')->onDelete('cascade');
            $table->foreign('created_by', 'fk_geofences_created_by')->references('id')->on('admins');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};