<?php

namespace App\Http\Middleware;

use App\Domains\Authentication\Services\AuthService;
use App\Domains\Guards\Models\Guard;
use Closure;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * GuardAuth — Stateless JWT authentication for the mobile (guard) API.
 *
 * Guards authenticate with a Bearer access token issued by
 * AuthService::authenticateGuard(). This middleware is the gate on every
 * protected `mobile/v1` route. It:
 *
 *   1. Pulls the Bearer token from the Authorization header.
 *   2. Verifies the JWT signature + expiry (signed with APP_KEY, HS256).
 *   3. Confirms the token is an *access* token (not a refresh token).
 *   4. Confirms a matching, non-invalidated, non-expired row still exists in
 *      `guard_sessions` — this enforces the single-active-session rule:
 *      logging in on a new device deletes the old session row, so the old
 *      device's still-valid-by-signature token is rejected here.
 *   5. Confirms the guard account is still employment_status = active.
 *   6. Attaches the Guard model to the request (`$request->user()` and
 *      `$request->attributes->get('guard')`) for downstream controllers.
 *
 * Every failure returns the standard mobile error envelope (see
 * Details/Important/MOBILE_API_INTEGRATION.md §3.2) so the Flutter client can
 * branch on `error.code`:
 *   - UNAUTHENTICATED (401) — no token sent
 *   - TOKEN_EXPIRED   (401) — signature ok but expired → app runs refresh flow
 *   - TOKEN_INVALID   (401) — bad signature / wrong type / superseded session
 *
 * This is distinct from AdminAuth, which is session-based.
 */
class GuardAuth
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (empty($token)) {
            return $this->reject('UNAUTHENTICATED', 'Authentication token is required.');
        }

        // Verify signature + expiry. Distinguish expired (refreshable) from
        // otherwise invalid so the app knows whether to run the refresh flow.
        try {
            $payload = $this->authService->decodeGuardToken($token);
        } catch (ExpiredException $e) {
            return $this->reject('TOKEN_EXPIRED', 'Your session has expired. Please refresh and try again.');
        } catch (\Throwable $e) {
            return $this->reject('TOKEN_INVALID', 'Invalid authentication token.');
        }

        // Reject refresh tokens (and anything that isn't an access token) on
        // protected routes — refresh tokens are only valid at /auth/refresh.
        if (($payload['type'] ?? null) !== 'access') {
            return $this->reject('TOKEN_INVALID', 'Invalid authentication token.');
        }

        $guardId = $payload['sub'] ?? null;

        if (empty($guardId)) {
            return $this->reject('TOKEN_INVALID', 'Invalid authentication token.');
        }

        // Single-session enforcement: the token must hash to the guard's
        // current, live session row. A login on another device deletes this
        // row, so a superseded token lands here as TOKEN_INVALID.
        $session = DB::table('guard_sessions')
            ->where('guard_id', $guardId)
            ->where('access_token_hash', hash('sha256', $token))
            ->whereNull('invalidated_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$session) {
            return $this->reject('TOKEN_INVALID', 'Your session is no longer valid. Please sign in again.');
        }

        $guard = Guard::where('id', $guardId)
            ->where('employment_status', 'active')
            ->first();

        if (!$guard) {
            return $this->reject('TOKEN_INVALID', 'Your account is not active. Contact your supervisor.');
        }

        // Make the authenticated guard available to controllers.
        $request->setUserResolver(fn () => $guard);
        $request->attributes->set('guard', $guard);
        $request->attributes->set('guard_session_id', $session->id);

        return $next($request);
    }

    /**
     * Build the standard mobile error envelope with a 401 status.
     * Mirrors MOBILE_API_INTEGRATION.md §3.2.
     */
    private function reject(string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], 401);
    }
}
