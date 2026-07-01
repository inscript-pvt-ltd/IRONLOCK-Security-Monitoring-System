<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GDPR right-to-erasure marker on guards (Phase 8).
 *
 * Erasure anonymises a guard's PII in place (name, email, phone, username, SIA
 * number) while PRESERVING the append-only audit trail (shift_events, alerts,
 * photo evidence, reports) that is legally required (BS 8484 / regulatory
 * defensibility). `erased_at` records when identity was redacted so erased
 * guards can be excluded from scheduling/active lists while remaining FK-valid
 * for the historical rows that still reference them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->timestamp('erased_at')->nullable()->after('employment_status');
            $table->index('erased_at', 'idx_guards_erased_at');
        });
    }

    public function down(): void
    {
        Schema::table('guards', function (Blueprint $table) {
            $table->dropIndex('idx_guards_erased_at');
            $table->dropColumn('erased_at');
        });
    }
};
