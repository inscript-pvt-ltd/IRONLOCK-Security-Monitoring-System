<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds an optional postal `address` and an optional profile `photo_path`
     * to guards, and relaxes `username` to nullable.
     *
     * `username` is no longer collected on the admin form (guards log in with
     * their employee code or email — the column was never a login credential),
     * but it is kept in the schema because the mobile `me` endpoint still
     * serialises it; making it nullable lets new guards be created without one
     * while existing values are untouched. The UNIQUE index is retained — MySQL/
     * MariaDB exempt NULLs from uniqueness, so multiple username-less guards are
     * allowed.
     */
    public function up(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->string('username', 100)->nullable()->change();
            $table->string('address', 500)->nullable()->after('phone');
            // Relative path on the private `photos` disk (guards/…). Served only
            // through an admin-authenticated route, never publicly.
            $table->string('photo_path')->nullable()->after('address');
        });
    }

    /**
     * Reverse the migrations.
     *
     * `username` is intentionally NOT reverted to NOT NULL here: rows created
     * after this migration may legitimately hold NULL, and forcing the column
     * back would fail on that data. Only the two added columns are dropped.
     */
    public function down(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->dropColumn(['address', 'photo_path']);
        });
    }
};
