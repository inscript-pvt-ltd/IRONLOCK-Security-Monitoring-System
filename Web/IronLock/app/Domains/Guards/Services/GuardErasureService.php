<?php

namespace App\Domains\Guards\Services;

use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Remove a guard from the active roster (Phase 8 · SEC-004, revised).
 *
 * IronLock removes a guard by ARCHIVING, not deletion and not anonymisation:
 * the guard's real details are PRESERVED in place so the append-only audit
 * trail (shift_events, alerts, photo evidence, reports) — and every historical
 * shift that references this guard — keeps showing the real name and licence.
 * The FK from those rows stays valid; only the guard's presence on the live
 * roster and their ability to sign in are removed.
 *
 * The operation:
 *   - stamps erased_at + marks the guard inactive, which removes them from the
 *     roster and shift picker (Guard::notErased()), from SIA-expiry checks, and
 *     from any new shift assignment (ShiftController::guardAssignableError);
 *   - invalidates every live session and the account is inactive, so the guard
 *     can no longer sign in (GuardAuth rejects non-active accounts per request);
 *   - leaves all identifying data intact, so old shifts still resolve the real
 *     guard details and the removal is reversible (restore = clear erased_at +
 *     set active).
 *
 * NOTE: this is a data-preserving removal, not a GDPR right-to-erasure. A
 * genuine "destroy my personal data" request would need a separate hard-redact
 * path — this deliberately keeps the data for historical shifts.
 */
class GuardErasureService
{
    /**
     * @return array{success:bool, error?:string}
     */
    public function erase(Guard $guard): array
    {
        if ($guard->isErased()) {
            return ['success' => false, 'error' => 'This guard has already been removed.'];
        }

        // Refuse while the guard is on duty — an active/checked-in shift must be
        // ended or resolved first, otherwise a live monitoring session would be
        // left pointing at a removed guard.
        $onDuty = Shift::where('guard_id', $guard->id)
            ->whereIn('status', [Shift::STATUS_ACTIVE, Shift::STATUS_CHECKED_IN])
            ->exists();
        if ($onDuty) {
            return ['success' => false, 'error' => 'This guard has an active or checked-in shift. End or resolve it before removing.'];
        }

        $now = Carbon::now();

        DB::transaction(function () use ($guard, $now) {
            // Preserve all identifying data — only take the guard off the active
            // roster and stamp the removal time. employment_status = inactive
            // stops any new scheduling; erased_at hides them from lists/pickers.
            $guard->update([
                'employment_status' => 'inactive',
                'erased_at' => $now,
            ]);

            // Invalidate every live session. Combined with the inactive status
            // (which GuardAuth re-checks each request) this signs the guard out
            // and keeps them out — no need to destroy credentials, so a restore
            // stays clean.
            DB::table('guard_sessions')
                ->where('guard_id', $guard->id)
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => $now]);
        });

        return ['success' => true];
    }
}
