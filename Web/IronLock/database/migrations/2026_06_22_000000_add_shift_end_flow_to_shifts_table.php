<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift-end accountability + early-end approval flow.
 * Spec: Details/Important/BACKEND_SHIFT_END_SPEC.md
 *
 * Two things this enables:
 *  1. Every completed shift carries an end_type so payroll/reporting can tell
 *     a normal end (guard) from an authorised early finish (early) from a
 *     server-forced close (auto). auto_closed flags the last for supervisors.
 *  2. A guard can only leave before scheduled_end once a supervisor approves a
 *     request — the early_end_* columns hold that one open request per shift
 *     (pending → approved|rejected, overwritten on re-request).
 *
 * end_reason/end_note record why an early end happened, kept separate from the
 * attendance_reason/attendance_note the supervisor-resolution flow already uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            // How the shift was closed. Null until completed.
            $table->enum('end_type', ['guard', 'early', 'auto'])->nullable()->after('ended_early');
            // True when the server force-closed an overdue shift (no trustworthy
            // end signal from the guard) — surfaced in supervisor reporting.
            $table->boolean('auto_closed')->default(false)->after('end_type');
            // Reason/note captured on an early end (mirrors the approved request).
            $table->string('end_reason', 100)->nullable()->after('auto_closed');
            $table->text('end_note')->nullable()->after('end_reason');

            // The single open early-end request for this shift.
            $table->enum('early_end_status', ['pending', 'approved', 'rejected'])->nullable()->after('end_note');
            $table->string('early_end_reason', 100)->nullable()->after('early_end_status');
            $table->text('early_end_note')->nullable()->after('early_end_reason');
            $table->timestamp('early_end_requested_at')->nullable()->after('early_end_note');
            $table->timestamp('early_end_decided_at')->nullable()->after('early_end_requested_at');
            $table->char('early_end_decided_by', 36)->nullable()->after('early_end_decided_at');

            $table->foreign('early_end_decided_by', 'fk_shifts_early_end_decided_by')
                ->references('id')->on('admins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropForeign('fk_shifts_early_end_decided_by');
            $table->dropColumn([
                'end_type',
                'auto_closed',
                'end_reason',
                'end_note',
                'early_end_status',
                'early_end_reason',
                'early_end_note',
                'early_end_requested_at',
                'early_end_decided_at',
                'early_end_decided_by',
            ]);
        });
    }
};
