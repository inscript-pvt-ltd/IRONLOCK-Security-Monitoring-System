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
        Schema::table('guard_locations', function (Blueprint $table) {
            // Add the missing foreign key constraint to shifts table
            $table->foreign('shift_id', 'fk_guard_locations_shift')->references('id')->on('shifts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('guard_locations', function (Blueprint $table) {
            $table->dropForeign('fk_guard_locations_shift');
        });
    }
};