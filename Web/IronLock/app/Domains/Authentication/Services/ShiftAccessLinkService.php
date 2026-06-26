<?php

namespace App\Domains\Authentication\Services;

use App\Domains\Authentication\Models\ShiftAccessLink;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * ShiftAccessLinkService — mint and redeem one-time SSO sign-in links.
 *
 * A link removes the password step only; redemption still passes the same login
 * gates as Mobile\AuthController::login (employment status, account lock, and the
 * shift check-in window via GuardLoginWindow) and reuses AuthService to mint the
 * session. The raw token is returned once (in the URL) and never stored — only
 * its SHA-256 hash is persisted.
 */
class ShiftAccessLinkService
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly GuardLoginWindow $loginWindow,
    ) {
    }

    /**
     * Generate a fresh single-use link for a shift, superseding any prior unused
     * link for the same shift. Returns the full URL (raw token embedded) and the
     * expiry. The raw token exists only here and in the returned URL.
     *
     * @return array{url: string, token: string, expires_at: Carbon}
     */
    public function generate(Shift $shift, ?string $adminId = null): array
    {
        // Cryptographically secure 256-bit token; only its hash is stored.
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = Carbon::now()->addMinutes($this->ttlMinutes());

        DB::transaction(function () use ($shift, $tokenHash, $adminId, $expiresAt) {
            // Invalidate any previous unused, not-yet-superseded link so only the
            // newest one can ever be redeemed (replay/regeneration safety).
            DB::table('shift_access_links')
                ->where('shift_id', $shift->id)
                ->whereNull('used_at')
                ->whereNull('invalidated_at')
                ->update(['invalidated_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

            ShiftAccessLink::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'token_hash' => $tokenHash,
                'created_by' => $adminId,
                'used_at' => null,
                'invalidated_at' => null,
                'expires_at' => $expiresAt,
            ]);
        });

        $this->logEvent($shift, 'ACCESS_LINK_GENERATED', [
            'by' => $adminId,
            'expires_at' => $expiresAt->toISOString(),
        ]);

        Log::info('Shift access link generated', [
            'shift_id' => $shift->id,
            'guard_id' => $shift->guard_id,
            'by' => $adminId,
            'expires_at' => $expiresAt->toISOString(),
        ]);

        return [
            'url' => $this->buildUrl($rawToken),
            'token' => $rawToken,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Redeem a raw token. Validates the link lifecycle, the guard, the shift and
     * the login window, consumes the link single-use, records the check-in and
     * mints a guard session — mirroring the password login flow exactly.
     *
     * Returns a structured result; the controller maps the code to an HTTP
     * status and the mobile error envelope.
     *
     * @return array{
     *   success: bool, code?: string, error?: string, details?: array,
     *   guard?: Guard, shift?: Shift, checked_in?: bool,
     *   tokens?: array, hmac_secret?: string
     * }
     */
    public function redeem(string $rawToken, array $deviceInfo = []): array
    {
        $rawToken = trim($rawToken);
        if ($rawToken === '') {
            return $this->fail('SHIFT_ACCESS_INVALID', 'This access link is invalid.');
        }

        $link = ShiftAccessLink::where('token_hash', hash('sha256', $rawToken))->first();

        if (!$link) {
            return $this->fail('SHIFT_ACCESS_INVALID', 'This access link is invalid.');
        }

        if ($link->used_at !== null) {
            return $this->fail('SHIFT_ACCESS_USED', 'This access link has already been used.');
        }

        if ($link->invalidated_at !== null) {
            // Superseded by a newer link — treat as invalid to the holder.
            return $this->fail('SHIFT_ACCESS_INVALID', 'This access link is no longer valid. A newer link has been issued.');
        }

        if ($link->expires_at === null || Carbon::now()->greaterThanOrEqualTo($link->expires_at)) {
            return $this->fail('SHIFT_ACCESS_EXPIRED', 'This access link has expired. Please ask your supervisor for a new one.');
        }

        $shift = Shift::find($link->shift_id);

        if (!$shift || in_array($shift->status, [Shift::STATUS_COMPLETED, Shift::STATUS_CANCELLED], true)) {
            return $this->fail('SHIFT_ACCESS_SHIFT_INVALID', 'This shift is no longer available.');
        }

        $guard = Guard::find($link->guard_id);

        // The link must belong to the shift's currently assigned, active guard —
        // mirrors authenticateGuard()'s active/lock checks (a reassigned shift
        // invalidates an old guard's link).
        if (!$guard || $guard->employment_status !== 'active' || $shift->guard_id !== $link->guard_id) {
            return $this->fail('SHIFT_ACCESS_UNAUTHORIZED', 'You are not authorized to use this access link.');
        }

        if ($guard->account_locked_at) {
            return $this->fail('ACCOUNT_LOCKED', 'Account locked. Contact your supervisor.');
        }

        // Same login-window authority as the password path.
        $window = $this->loginWindow->forShift($shift);

        if (!($window['open'] ?? false)) {
            return [
                'success' => false,
                'code' => 'LOGIN_WINDOW_CLOSED',
                'error' => $window['message'] ?? 'Sign-in is not permitted for this shift right now.',
                'details' => $window['details'] ?? null,
            ];
        }

        // Consume the link single-use and atomically — guards against a replay or
        // a concurrent second redemption (only one UPDATE can flip used_at).
        $consumed = DB::table('shift_access_links')
            ->where('id', $link->id)
            ->whereNull('used_at')
            ->whereNull('invalidated_at')
            ->update(['used_at' => Carbon::now(), 'updated_at' => Carbon::now()]);

        if ($consumed === 0) {
            return $this->fail('SHIFT_ACCESS_USED', 'This access link has already been used.');
        }

        // Record the check-in (login ≠ start), exactly as password login does.
        $checkedIn = false;
        if (in_array($shift->status, [Shift::STATUS_SCHEDULED, Shift::STATUS_MISSED], true)) {
            $checkedIn = $shift->checkIn();
        }

        // Mint the session + ensure the HMAC secret — reuse AuthService.
        $tokens = $this->authService->issueGuardSession($guard, $deviceInfo);
        $hmacSecret = $this->authService->ensureHmacSecret($guard);

        $this->logEvent($shift, 'ACCESS_LINK_REDEEMED', [
            'link_id' => $link->id,
            'checked_in' => $checkedIn,
        ]);

        Log::info('Shift access link redeemed', [
            'shift_id' => $shift->id,
            'guard_id' => $guard->id,
            'link_id' => $link->id,
        ]);

        return [
            'success' => true,
            'guard' => $guard,
            'shift' => $shift,
            'checked_in' => $checkedIn,
            'tokens' => $tokens,
            'hmac_secret' => $hmacSecret,
        ];
    }

    /**
     * Build the link URL the admin copies: <base>/<rawToken>. The mobile app
     * intercepts this deep link and posts the token to the redemption endpoint.
     */
    private function buildUrl(string $rawToken): string
    {
        $base = config('ironlock.shift_access_link_base');

        if (empty($base)) {
            $base = rtrim((string) config('app.url'), '/') . '/m/shift-access';
        }

        return rtrim($base, '/') . '/' . $rawToken;
    }

    private function ttlMinutes(): int
    {
        return (int) config('ironlock.shift_access_link_ttl_minutes', 60);
    }

    /**
     * @return array{success: false, code: string, error: string}
     */
    private function fail(string $code, string $error): array
    {
        return ['success' => false, 'code' => $code, 'error' => $error];
    }

    /**
     * Best-effort immutable audit row. A logging failure must never block the
     * generate/redeem flow, so swallow and move on (server timestamp set in PHP
     * UTC, not the DB default).
     */
    private function logEvent(Shift $shift, string $eventType, array $metadata = []): void
    {
        try {
            \App\Domains\Shifts\Models\ShiftEvent::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'event_type' => $eventType,
                'metadata' => $metadata,
                'recorded_at' => Carbon::now(),
                'server_received_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is non-critical to the request; do not surface.
        }
    }
}
