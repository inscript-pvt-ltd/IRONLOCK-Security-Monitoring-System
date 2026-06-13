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
        // Guard Live Location — one mutable row per guard (NOT append-only, NO history)
        // Each 15-second GPS ping REPLACES the previous coordinates via UPSERT
        Schema::create('guard_locations', function (Blueprint $table) {
            $table->char('guard_id', 36)->primary(); // One live row per guard
            $table->char('shift_id', 36);
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->decimal('accuracy', 8, 2)->nullable();
            $table->integer('battery_level')->nullable();
            $table->enum('zone_status', ['INSIDE_ZONE', 'OUTSIDE_ZONE']);
            $table->timestamp('recorded_at')->nullable(); // Device timestamp (diagnostic only)
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate(); // Authoritative "last seen"

            // Indexes
            $table->index('shift_id', 'idx_guard_locations_shift_id');
            $table->index('zone_status', 'idx_guard_locations_zone_status');
            $table->index('updated_at', 'idx_guard_locations_updated_at');

            // Foreign Keys
            $table->foreign('guard_id', 'fk_guard_locations_guard')->references('id')->on('guards');
            // Note: shift_id foreign key will be added later after shifts table is created
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('guard_locations');
    }
};