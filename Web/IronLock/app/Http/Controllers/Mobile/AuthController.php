<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Authentication\Services\AuthService;
use App\Domains\Guards\Models\Guard;
use App\Domains\Shifts\Models\Shift;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

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

        // 3. Window open → mint the session.
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
        return $this->apiSuccess(['guard' => $this->guardPayload($this->currentGuard($request))]);
    }

    /**
     * Determine whether the guard's shift-bound login window is open.
     *
     * Rule (MOBILE_API_INTEGRATION.md §5.1): a guard may sign in only from
     * 10 minutes before a shift's scheduled start until the shift ends — i.e.
     * while a shift is `active`, or a `scheduled` shift starts within 10 min
     * and hasn't passed its scheduled end. Otherwise the response carries the
     * next window so the app can show a countdown.
     *
     * @return array{open: bool, message?: string, details?: array}
     */
    private function loginWindow(Guard $guard): array
    {
        $now = now();

        // A shift in progress always permits sign-in.
        $hasActive = Shift::where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_ACTIVE)
            ->exists();

        if ($hasActive) {
            return ['open' => true];
        }

        // A scheduled shift whose window is open (starts within 10 min and is
        // not yet past its scheduled end).
        $hasOpenScheduled = Shift::where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_SCHEDULED)
            ->where('scheduled_start', '<=', $now->copy()->addMinutes(10))
            ->where('scheduled_end', '>=', $now)
            ->exists();

        if ($hasOpenScheduled) {
            return ['open' => true];
        }

        // Closed — surface when the next window opens, if any.
        $next = Shift::where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_SCHEDULED)
            ->where('scheduled_start', '>', $now)
            ->orderBy('scheduled_start')
            ->first();

        if (!$next) {
            return [
                'open' => false,
                'message' => 'You have no upcoming shift. You can sign in 10 minutes before your next scheduled shift.',
                'details' => ['window_opens_at' => null, 'next_shift_start' => null],
            ];
        }

        $opensAt = $next->scheduled_start->copy()->subMinutes(10);
        $tz = config('app.timezone') ?: 'UTC';

        $message = sprintf(
            'You can sign in from %s — 10 minutes before your %s shift.',
            $opensAt->copy()->setTimezone($tz)->format('H:i'),
            $next->scheduled_start->copy()->setTimezone($tz)->format('H:i')
        );

        return [
            'open' => false,
            'message' => $message,
            'details' => [
                'window_opens_at' => $opensAt->toISOString(),
                'next_shift_start' => $next->scheduled_start->toISOString(),
            ],
        ];
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
