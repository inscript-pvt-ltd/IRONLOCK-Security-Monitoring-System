<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Authentication\Services\AuthService;
use App\Domains\Authentication\Services\GuardLoginWindow;
use App\Domains\Authentication\Services\ShiftAccessLinkService;
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

    public function __construct(
        private readonly AuthService $authService,
        private readonly GuardLoginWindow $loginWindow,
        private readonly ShiftAccessLinkService $accessLinks,
    ) {
    }

    /**
     * POST /auth/login — authenticate a guard and issue tokens.
     */
    public function login(Request $request): JsonResponse
    {
        \Log::info('Mobile Login Request', [
            'body' => $request->except('password'),
            'ip'   => $request->ip(),
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
        $window = $this->loginWindow->forGuard($guard);

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
            // Shared HMAC key for signing photo-upload payloads (spec §12.5).
            // Delivered once here; store it in the device secure keychain.
            'hmac_secret' => $this->authService->ensureHmacSecret($guard),
            'guard' => $this->guardPayload($guard),
        ]);
    }

    /**
     * POST /auth/refresh — mint a new access token from a valid refresh token.
     */
    public function refresh(Request $request): JsonResponse
    {
        \Log::info('Mobile Refresh Request', [
            'body' => $request->all(),
            'ip'   => $request->ip(),
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
            'body' => $request->all(),
            'ip'   => $request->ip(),
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
            'body' => $request->all(),
            'ip'   => $request->ip(),
        ]);

        return $this->apiSuccess(['guard' => $this->guardPayload($this->currentGuard($request))]);
    }

    /**
     * POST /auth/shift-access — redeem a one-time Shift Access Link (SSO).
     *
     * Public + throttled, like login. The link removes the password step only:
     * redemption runs the same gates (employment status, account lock, and the
     * shift check-in window) and, on success, returns the identical envelope as
     * login() so the app reuses its existing handling. Any failure returns the
     * matching error code/message for the app to surface and bounce the guard
     * back to its login screen.
     */
    public function shiftAccess(Request $request): JsonResponse
    {
        \Log::info('Mobile Shift Access Request', [
            'body' => $request->except('token'),
            'ip'   => $request->ip(),
        ]);

        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'device' => ['sometimes', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        $result = $this->accessLinks->redeem(
            (string) $request->input('token'),
            (array) $request->input('device', [])
        );

        if (!($result['success'] ?? false)) {
            $code = $result['code'] ?? 'SHIFT_ACCESS_INVALID';
            $status = match ($code) {
                'ACCOUNT_LOCKED' => 423,
                'LOGIN_WINDOW_CLOSED', 'SHIFT_ACCESS_UNAUTHORIZED' => 403,
                'VALIDATION_ERROR' => 422,
                default => 401, // SHIFT_ACCESS_INVALID / _EXPIRED / _USED / _SHIFT_INVALID
            };

            return $this->apiError($code, $result['error'] ?? 'Unable to use this access link.', $status, $result['details'] ?? null);
        }

        /** @var Guard $guard */
        $guard = $result['guard'];
        /** @var Shift $shift */
        $shift = $result['shift'];

        // Mirror login(): a freshly checked-in shift gets an audit row.
        if (($result['checked_in'] ?? false) && $shift instanceof Shift) {
            $this->logShiftEvent($shift, 'CHECKED_IN', [
                'source' => 'shift_access_link',
                'scheduled_start' => optional($shift->scheduled_start)->toISOString(),
                'late' => $shift->hasActiveOverride(),
            ]);
        }

        $tokens = $result['tokens'];

        return $this->apiSuccess([
            'token_type' => 'Bearer',
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at']->toISOString(),
            'hmac_secret' => $result['hmac_secret'],
            'guard' => $this->guardPayload($guard),
        ]);
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
