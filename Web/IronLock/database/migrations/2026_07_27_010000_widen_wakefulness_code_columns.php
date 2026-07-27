<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen wakefulness_checks.challenge_code / submitted_code from varchar(4) to
 * varchar(8).
 *
 * Two reasons, both pre-existing:
 *
 * 1. `submitted_code` stores whatever the guard typed, and the response
 *    endpoints validate it as `string|max:8`. A guard fat-fingering a 5th
 *    character therefore passed validation and then hit MySQL strict mode on
 *    save ("Data too long for column") — a 500 where the correct outcome is a
 *    clean FAILED / WRONG_CODE. The column now holds everything the validator
 *    admits.
 *
 * 2. `challenge_code` hardcoded 4 while config('ironlock.totp_digits') is
 *    advertised as configurable and is handed to the device in the shift-start
 *    payload. digits() is clamped to 4-8; the column now covers that range, so
 *    the knob is real rather than silently truncating at the storage layer.
 *
 * Widening a varchar is non-destructive: existing 4-character codes are
 * unchanged and every comparison in WakefulnessService is an exact hash_equals
 * on the stored string, so nothing about validation shifts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wakefulness_checks', function (Blueprint $table) {
            $table->string('challenge_code', 8)->change();
            $table->string('submitted_code', 8)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('wakefulness_checks', function (Blueprint $table) {
            $table->string('challenge_code', 4)->change();
            $table->string('submitted_code', 4)->nullable()->change();
        });
    }
};
