<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Shift attendance & status management.
 *
 * Adds the Checked-In and Missed statuses to the shift lifecycle plus the
 * columns the supervisor recovery flow needs:
 *  - checked_in_at        : when the guard signed in within the window
 *  - checkin_override_until: per-shift late-check-in grant (supervisor)
 *  - late_authorized_by    : admin who authorized the late check-in
 *  - attendance_reason     : category for a missed/excused outcome
 *  - attendance_note       : supervisor free-text note
 *
 * The enum is widened via a raw MODIFY because Doctrine cannot diff MySQL
 * enums; the existing scheduled|active|completed|cancelled values are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE shifts MODIFY COLUMN status "
            . "ENUM('scheduled', 'checked_in', 'active', 'completed', 'cancelled', 'missed') "
            . "NOT NULL DEFAULT 'scheduled'"
        );

        Schema::table('shifts', function (Blueprint $table) {
            $table->timestamp('checked_in_at')->nullable()->after('actual_end');
            $table->timestamp('checkin_override_until')->nullable()->after('checked_in_at');
            $table->char('late_authorized_by', 36)->nullable()->after('checkin_override_until');
            $table->string('attendance_reason', 50)->nullable()->after('late_authorized_by');
            $table->text('attendance_note')->nullable()->after('attendance_reason');

            $table->foreign('late_authorized_by', 'fk_shifts_late_authorized_by')
                ->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign('fk_shifts_late_authorized_by');
            $table->dropColumn([
                'checked_in_at',
                'checkin_override_until',
                'late_authorized_by',
                'attendance_reason',
                'attendance_note',
            ]);
        });

        // Restore the original enum. Any rows left in a new state would block
        // the narrowing, so fold them back to safe legacy values first.
        DB::statement("UPDATE shifts SET status = 'scheduled' WHERE status IN ('checked_in')");
        DB::statement("UPDATE shifts SET status = 'cancelled' WHERE status IN ('missed')");

        DB::statement(
            "ALTER TABLE shifts MODIFY COLUMN status "
            . "ENUM('scheduled', 'active', 'completed', 'cancelled') "
            . "NOT NULL DEFAULT 'scheduled'"
        );
    }
};
