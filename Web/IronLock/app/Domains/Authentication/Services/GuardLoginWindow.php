<?php

namespace App\Domains\Authentication\Services;

use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;

/**
 * GuardLoginWindow — the single source of truth for "may this guard sign in
 * right now?", shared by the password login (Mobile\AuthController::login) and
 * the one-time Shift Access Link (SSO) redemption.
 *
 * The window rule itself lives on the Shift model (windowMinutes / canCheckIn /
 * hasActiveOverride); this service only orchestrates which shift to evaluate and
 * shapes the "too early" / "expired" / "no shift" hints the app already handles.
 * forGuard() is the exact logic previously inlined in AuthController::login —
 * moved here verbatim so behaviour is unchanged — and forShift() applies the
 * same rule to one specific shift (the link is bound to a single shift).
 *
 * @phpstan-type WindowResult array{open: bool, shift?: ?Shift, message?: string, details?: array}
 */
class GuardLoginWindow
{
    /**
     * Evaluate the guard's shift-bound login window across all their shifts.
     *
     * Rule (Shift Attendance spec): a guard may sign in from
     * `scheduled_start − window` to `scheduled_start + window` (window =
     * config('ironlock.check_in_window_minutes')), while a shift is already
     * `active`, or any time a supervisor has authorized a late check-in. The
     * matched shift is returned so the caller can move it to Checked-In.
     *
     * When closed, two distinct cases are distinguished for the app:
     *  - too early  → carries window_opens_at so the app can show a countdown
     *  - expired    → "contact your supervisor" (the window has passed)
     *
     * @return array{open: bool, shift?: ?Shift, message?: string, details?: array}
     */
    public function forGuard(Guard $guard): array
    {
        $now = now();
        $window = Shift::windowMinutes();

        // A shift in progress always permits sign-in (no transition needed).
        $active = Shift::where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_ACTIVE)
            ->orderBy('scheduled_start')
            ->first();

        if ($active) {
            return ['open' => true, 'shift' => $active];
        }

        // A shift whose check-in window is currently open: inside ±window of
        // its scheduled start, or carrying a live supervisor override. Pull the
        // near-term candidates and let the model own the window arithmetic so
        // the override clause stays in one place.
        $candidate = Shift::where('guard_id', $guard->id)
            ->whereIn('status', [Shift::STATUS_SCHEDULED, Shift::STATUS_CHECKED_IN, Shift::STATUS_MISSED])
            ->where(function ($q) use ($now, $window) {
                $q->whereBetween('scheduled_start', [
                    $now->copy()->subMinutes($window),
                    $now->copy()->addMinutes($window),
                ])->orWhere('checkin_override_until', '>=', $now);
            })
            ->orderBy('scheduled_start')
            ->get()
            ->first(fn (Shift $s) => $s->canCheckIn());

        if ($candidate) {
            return ['open' => true, 'shift' => $candidate];
        }

        // Closed. Decide whether it is "too early" (a future window exists) or
        // "expired" (a shift today whose window has already passed).
        $next = Shift::where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_SCHEDULED)
            ->where('scheduled_start', '>', $now->copy()->addMinutes($window))
            ->orderBy('scheduled_start')
            ->first();

        if ($next) {
            $opensAt = $next->scheduled_start->copy()->subMinutes($window);
            $tz = config('app.timezone') ?: 'UTC';

            return [
                'open' => false,
                'message' => sprintf(
                    'You can sign in from %s — %d minutes before your %s shift.',
                    $opensAt->copy()->setTimezone($tz)->format('H:i'),
                    $window,
                    $next->scheduled_start->copy()->setTimezone($tz)->format('H:i')
                ),
                'details' => [
                    'reason' => 'too_early',
                    'window_opens_at' => $opensAt->toISOString(),
                    'next_shift_start' => $next->scheduled_start->toISOString(),
                ],
            ];
        }

        // A shift exists today but its check-in window has already expired.
        $expired = Shift::where('guard_id', $guard->id)
            ->whereIn('status', [Shift::STATUS_SCHEDULED, Shift::STATUS_CHECKED_IN, Shift::STATUS_MISSED])
            ->where('scheduled_start', '<=', $now)
            ->orderByDesc('scheduled_start')
            ->first();

        if ($expired) {
            return [
                'open' => false,
                'message' => 'The allowed check-in period for this shift has expired. Please contact your supervisor for assistance.',
                'details' => [
                    'reason' => 'expired',
                    'window_opens_at' => null,
                    'next_shift_start' => null,
                ],
            ];
        }

        return [
            'open' => false,
            'message' => sprintf(
                'You have no upcoming shift. You can sign in %d minutes before your next scheduled shift.',
                $window
            ),
            'details' => [
                'reason' => 'no_shift',
                'window_opens_at' => null,
                'next_shift_start' => null,
            ],
        ];
    }

    /**
     * Evaluate the login window for one specific shift (the Shift Access Link is
     * bound to a single shift). Applies the same model-level rule as forGuard()
     * via Shift::canCheckIn(), and shapes the same too_early / expired hints so
     * the app's existing LOGIN_WINDOW_CLOSED handling works identically.
     *
     * @return array{open: bool, shift?: ?Shift, message?: string, details?: array}
     */
    public function forShift(Shift $shift): array
    {
        $now = now();
        $window = Shift::windowMinutes();

        // Same authority as the password path: active shift, inside ±window, or
        // a live supervisor late-check-in override.
        if ($shift->canCheckIn()) {
            return ['open' => true, 'shift' => $shift];
        }

        $tz = config('app.timezone') ?: 'UTC';

        // Too early — a pre-active shift whose window has not opened yet and that
        // carries no override. Mirrors forGuard()'s countdown hint.
        if (in_array($shift->status, [Shift::STATUS_SCHEDULED, Shift::STATUS_CHECKED_IN, Shift::STATUS_MISSED], true)
            && $shift->scheduled_start !== null
            && !$shift->hasActiveOverride()
            && $now->lessThan($shift->scheduled_start->copy()->subMinutes($window))
        ) {
            $opensAt = $shift->scheduled_start->copy()->subMinutes($window);

            return [
                'open' => false,
                'message' => sprintf(
                    'You can sign in from %s — %d minutes before your %s shift.',
                    $opensAt->copy()->setTimezone($tz)->format('H:i'),
                    $window,
                    $shift->scheduled_start->copy()->setTimezone($tz)->format('H:i')
                ),
                'details' => [
                    'reason' => 'too_early',
                    'window_opens_at' => $opensAt->toISOString(),
                    'next_shift_start' => $shift->scheduled_start->toISOString(),
                ],
            ];
        }

        // Otherwise the window has passed (or the shift is in a state that can no
        // longer be signed into) — expired.
        return [
            'open' => false,
            'message' => 'The allowed check-in period for this shift has expired. Please contact your supervisor for assistance.',
            'details' => [
                'reason' => 'expired',
                'window_opens_at' => null,
                'next_shift_start' => null,
            ],
        ];
    }
}
