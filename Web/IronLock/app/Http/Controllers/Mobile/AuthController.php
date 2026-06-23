<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Authentication\Services\AuthService;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Carbon\Carbon;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Mobile guard authentication — login, token refresh, logout, profile.
 *
 * Contract: Details/Important/MOBILE_API_INTEGRATION.md §5.1–5.4.
 * Token mechanics live in AuthService; this controller orchestrates the flow
 * and the shift-bound login window, and shapes the §3.2 envelope.
 */
class AuthController extends Controller
{
    use InteractsWithMobileApi;

    public function __construct(private readonly AuthService $authService)
    {
    }

    /**
     * POST /auth/login — authenticate a guard and issue tokens.
     */
    public function login(Request $request): JsonResponse
    {
        \Log::info('Mobile Login Request', [
            'headers' => $request->headers->all(),
            'body'    => $request->except('password'),
            'ip'      => $request->ip(),
        ]);

        $validator = Validator::make($request->all(), [
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
            'device' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        $identifier = trim((string) $request->input('identifier'));
        $password = (string) $request->input('password');

        // 1. Credentials first — a wrong password returns INVALID_CREDENTIALS
        //    without revealing whether the account exists.
        $result = $this->authService->authenticateGuard($identifier, $password);

        if (!$result['success']) {
            return match ($result['code']) {
                'ACCOUNT_LOCKED' => $this->apiError('ACCOUNT_LOCKED', $result['error'], 423),
                default => $this->apiError('INVALID_CREDENTIALS', $result['error'], 401),
            };
        }

        /** @var Guard $guard */
        $guard = $result['guard'];

        // 2. Shift-bound login window — only after credentials pass.
        $window = $this->loginWindow($guard);

        if (!$window['open']) {
            return $this->apiError('LOGIN_WINDOW_CLOSED', $window['message'], 403, $window['details']);
        }

        // 3. Window open → record the check-in (login ≠ start) and mint the
        //    session. A matched scheduled/missed shift moves to Checked-In;
        //    an already-active shift is left untouched.
        if (($shift = $window['shift'] ?? null) instanceof Shift && $shift->checkIn()) {
            $this->logShiftEvent($shift, 'CHECKED_IN', [
                'source' => 'login',
                'scheduled_start' => optional($shift->scheduled_start)->toISOString(),
                'late' => $shift->hasActiveOverride(),
            ]);
        }

        $tokens = $this->authService->issueGuardSession($guard, (array) $request->input('device', []));

        return $this->apiSuccess([
            'token_type' => 'Bearer',
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at']->toISOString(),
            'guard' => $this->guardPayload($guard),
        ]);
    }

    /**
     * POST /auth/refresh — mint a new access token from a valid refresh token.
     */
    public function refresh(Request $request): JsonResponse
    {
        \Log::info('Mobile Refresh Request', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
            'ip'      => $request->ip(),
        ]);

        $validator = Validator::make($request->all(), [
            'refresh_token' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        $token = (string) $request->input('refresh_token');

        try {
            $payload = $this->authService->decodeGuardToken($token);
        } catch (ExpiredException $e) {
            return $this->apiError('TOKEN_EXPIRED', 'Your session has expired. Please sign in again.', 401);
        } catch (\Throwable $e) {
            return $this->apiError('TOKEN_INVALID', 'Invalid refresh token.', 401);
        }

        // Must be a refresh token, not an access token.
        if (($payload['type'] ?? null) !== 'refresh') {
            return $this->apiError('TOKEN_INVALID', 'Invalid refresh token.', 401);
        }

        $guardId = $payload['sub'] ?? null;

        if (empty($guardId)) {
            return $this->apiError('TOKEN_INVALID', 'Invalid refresh token.', 401);
        }

        // The refresh token must match the guard's current live session. Note
        // we do NOT gate on `expires_at` here — that column tracks the 2h
        // access-token expiry; the refresh token's own 7d exp (already verified
        // above) is its lifetime.
        $session = DB::table('guard_sessions')
            ->where('guard_id', $guardId)
            ->where('refresh_token_hash', hash('sha256', $token))
            ->whereNull('invalidated_at')
            ->first();

        if (!$session) {
            return $this->apiError('TOKEN_INVALID', 'Your session is no longer valid. Please sign in again.', 401);
        }

        $guard = Guard::where('id', $guardId)
            ->where('employment_status', 'active')
            ->first();

        if (!$guard) {
            return $this->apiError('TOKEN_INVALID', 'Your account is not active.', 401);
        }

        $tokens = $this->authService->refreshAccessToken($guard, $session->id);

        return $this->apiSuccess([
            'token_type' => 'Bearer',
            'access_token' => $tokens['access_token'],
            'expires_at' => $tokens['expires_at']->toISOString(),
        ]);
    }

    /**
     * POST /auth/logout — invalidate the current session. Protected route.
     */
    public function logout(Request $request): JsonResponse
    {
        \Log::info('Mobile Logout Request', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
            'ip'      => $request->ip(),
        ]);

        $guard = $this->currentGuard($request);

        if ($guard) {
            $this->authService->logoutGuard($guard->id);
        }

        return $this->apiSuccess(['message' => 'Logged out.']);
    }

    /**
     * GET /me — the authenticated guard's profile. Protected route.
     */
    public function me(Request $request): JsonResponse
    {
        \Log::info('Mobile Me Request', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
            'ip'      => $request->ip(),
        ]);

        return $this->apiSuccess(['guard' => $this->guardPayload($this->currentGuard($request))]);
    }

    /**
     * Determine whether the guard's shift-bound login window is open.
     *
     * Rule (Shift Attendance spec): a guard may sign in from
     * `scheduled_start − window` to `scheduled_start + window` (window =
     * config('ironlock.check_in_window_minutes')), while a shift is already
     * `active`, or any time a supervisor has authorized a late check-in. The
     * matched shift is returned so login() can move it to Checked-In.
     *
     * When closed, two distinct cases are distinguished for the app:
     *  - too early  → carries window_opens_at so the app can show a countdown
     *  - expired    → "contact your supervisor" (the window has passed)
     *
     * @return array{open: bool, shift?: ?Shift, message?: string, details?: array}
     */
    private function loginWindow(Guard $guard): array
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
     * Append an immutable shift_events audit row. Best-effort: a logging
     * failure must never block the auth flow, so swallow and move on.
     */
    private function logShiftEvent(Shift $shift, string $eventType, array $metadata = []): void
    {
        try {
            ShiftEvent::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'event_type' => $eventType,
                'metadata' => $metadata,
                'recorded_at' => Carbon::now(),
                // Authoritative server timestamp, assigned in PHP (UTC) at receipt.
                // Set explicitly so it never falls back to the DB CURRENT_TIMESTAMP
                // default, which uses the DB session timezone (not UTC).
                'server_received_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is non-critical to the request; do not surface.
        }
    }

    /**
     * The public guard profile shape (login §5.1 / me §5.4). Never includes
     * password or session internals.
     */
    private function guardPayload(Guard $guard): array
    {
        return [
            'id' => $guard->id,
            'employee_code' => $guard->employee_code,
            'first_name' => $guard->first_name,
            'last_name' => $guard->last_name,
            'username' => $guard->username,
            'email' => $guard->email,
            'phone' => $guard->phone,
            'sia_licence_number' => $guard->sia_licence_number,
            'sia_licence_expiry' => $guard->sia_licence_expiry?->format('Y-m-d'),
            'sia_licence_type' => $guard->sia_licence_type,
            'employment_status' => $guard->employment_status,
        ];
    }
}
