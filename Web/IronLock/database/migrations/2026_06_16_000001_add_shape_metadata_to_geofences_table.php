<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Geofences are always stored as a POLYGON (so MySQL ST_CONTAINS works), but a
 * geofence may have been *drawn* as a circle. Without remembering that, a circle
 * reloads as an anonymous polygon: its radius is lost, the radius box falls back
 * to the default, and it can no longer be resized. These two columns preserve the
 * original shape so circles survive a reload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            $table->string('shape_type', 16)->default('polygon')->after('name');
            $table->unsignedInteger('radius')->nullable()->after('shape_type'); // metres, circles only
        });
    }

    public function down(): void
    {
        Schema::table('geofences', function (Blueprint $table) {
            $table->dropColumn(['shape_type', 'radius']);
        });
    }
};
