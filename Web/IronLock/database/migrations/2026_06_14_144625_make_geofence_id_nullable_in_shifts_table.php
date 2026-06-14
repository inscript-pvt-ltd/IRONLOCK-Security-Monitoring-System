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
        Schema::table('shifts', function (Blueprint $table) {
            // Drop existing foreign key constraint
            $table->dropForeign('fk_shifts_geofence');

            // Make geofence_id nullable
            $table->char('geofence_id', 36)->nullable()->change();

            // Re-add foreign key with null support
            $table->foreign('geofence_id', 'fk_shifts_geofence')->references('id')->on('geofences')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign('fk_shifts_geofence');

            // Make geofence_id not nullable
            $table->char('geofence_id', 36)->nullable(false)->change();

            // Re-add original foreign key
            $table->foreign('geofence_id', 'fk_shifts_geofence')->references('id')->on('geofences');
        });
    }
};
