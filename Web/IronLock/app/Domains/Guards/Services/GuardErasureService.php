<?php

namespace App\Domains\Guards\Services;

use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GDPR right-to-erasure for a guard (Phase 8 · SEC-004).
 *
 * IronLock erases by ANONYMISATION, not deletion: the guard's identifying data
 * is redacted in place while the append-only audit trail (shift_events, alerts,
 * photo evidence, reports) is PRESERVED — that history is legally required for
 * regulatory defensibility (BS 8484) and the FK from those rows must stay
 * valid. Erasure removes identity, not history.
 *
 * The operation:
 *   - redacts PII (name, email, phone, username, SIA number) with sentinels;
 *   - destroys secrets (password, HMAC key, device + push identifiers);
 *   - invalidates every live session (the guard can no longer sign in);
 *   - marks the guard inactive + stamps erased_at (excluded from scheduling,
 *     rosters and SIA checks via Guard::notErased()).
 */
class GuardErasureService
{
    /**
     * @return array{success:bool, error?:string}
     */
    public function erase(Guard $guard): array
    {
        if ($guard->isErased()) {
            return ['success' => false, 'error' => 'This guard has already been erased.'];
        }

        // Refuse while the guard is on duty — an active/checked-in shift must be
        // ended or resolved first, otherwise a live monitoring session would be
        // left pointing at an anonymised record.
        $onDuty = Shift::where('guard_id', $guard->id)
            ->whereIn('status', [Shift::STATUS_ACTIVE, Shift::STATUS_CHECKED_IN])
            ->exists();
        if ($onDuty) {
            return ['success' => false, 'error' => 'This guard has an active or checked-in shift. End or resolve it before erasing.'];
        }

        $now = Carbon::now();
        // A short id fragment keeps redacted email/username unique across
        // multiple erasures (both columns are unique-indexed).
        $frag = substr(str_replace('-', '', (string) $guard->id), 0, 12);

        DB::transaction(function () use ($guard, $now, $frag) {
            $guard->update([
                'first_name' => 'Redacted',
                'last_name' => 'Guard',
                'email' => "erased-{$frag}@redacted.invalid",
                'phone' => null,
                'username' => "erased-{$frag}",
                'sia_licence_number' => null,
                // Destroy authentication + device secrets so the record can never
                // be used to sign in or be traced to a device again.
                'password' => bcrypt(Str::random(40)),
                'hmac_secret' => null,
                'device_identifier' => null,
                'device_name' => null,
                'push_token' => null,
                'push_token_platform' => null,
                'employment_status' => 'inactive',
                'erased_at' => $now,
            ]);

            // Invalidate every live session for this guard.
            DB::table('guard_sessions')
                ->where('guard_id', $guard->id)
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now]);
        });

        return ['success' => true];
    }
}
