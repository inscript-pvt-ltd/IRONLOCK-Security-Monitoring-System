<?php

namespace App\Domains\Authentication\Services;

use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService
{
    private const RATE_LIMIT_ATTEMPTS = 5;
    private const RATE_LIMIT_WINDOW = 900; // 15 minutes
    private const LOCKOUT_DURATION = 1800; // 30 minutes

    /**
     * Authenticate admin for web dashboard.
     */
    public function authenticateAdmin(string $email, string $password, string $ipAddress): array
    {
        // Check rate limiting
        $rateLimitKey = "admin_auth_attempts:{$ipAddress}";
        $attempts = Cache::get($rateLimitKey, 0);

        if ($attempts >= self::RATE_LIMIT_ATTEMPTS) {
            return [
                'success' => false,
                'error' => 'Too many failed attempts. Please try again later.',
                'locked_until' => now()->addSeconds(self::LOCKOUT_DURATION)
            ];
        }

        $admin = Admin::where('email', $email)->where('status', 'active')->first();

        if (!$admin || !Hash::check($password, $admin->password)) {
            // Increment failed attempts
            Cache::put($rateLimitKey, $attempts + 1, self::RATE_LIMIT_WINDOW);

            return [
                'success' => false,
                'error' => 'Invalid credentials.',
                'attempts_remaining' => self::RATE_LIMIT_ATTEMPTS - ($attempts + 1)
            ];
        }

        // Clear rate limiting on success
        Cache::forget($rateLimitKey);

        // Update last login
        $admin->update(['last_login_at' => now()]);

        // Create admin session
        $sessionId = $this->createAdminSession($admin);

        return [
            'success' => true,
            'admin' => $admin,
            'session_id' => $sessionId
        ];
    }

    /**
     * Verify a guard's mobile login credentials.
     *
     * The guard logs in with their **employee code OR email** (the `username`
     * column is internal-only and is NOT a login credential — see
     * MOBILE_API_INTEGRATION.md §9 decision #1).
     *
     * This ONLY checks credentials and the lock state; it does NOT issue any
     * token. Token issuance is deliberately split into issueGuardSession() so
     * the caller can enforce additional gates (e.g. the shift login window)
     * before a session is created — a rejected login must not mint a token.
     *
     * @return array{success: bool, guard?: Guard, code?: string, error?: string}
     */
    public function authenticateGuard(string $identifier, string $password): array
    {
        $guard = Guard::where('employment_status', 'active')
            ->where(function ($query) use ($identifier) {
                $query->where('employee_code', $identifier)
                    ->orWhere('email', $identifier);
            })
            ->first();

        // Check if account is locked
        if ($guard && $guard->account_locked_at) {
            return [
                'success' => false,
                'error' => 'Account locked. Contact your supervisor.',
                'code' => 'ACCOUNT_LOCKED'
            ];
        }

        if (!$guard || !Hash::check($password, $guard->password)) {
            // Increment failed login count
            if ($guard) {
                $failedCount = $guard->failed_login_count + 1;
                $updates = ['failed_login_count' => $failedCount];

                // Lock account after 5 failed attempts
                if ($failedCount >= 5) {
                    $updates['account_locked_at'] = now();
                }

                $guard->update($updates);
            }

            return [
                'success' => false,
                'error' => 'Invalid credentials.',
                'code' => 'INVALID_CREDENTIALS'
            ];
        }

        // Credentials valid — clear the failed-attempt counter. Tokens are
        // issued separately via issueGuardSession().
        $guard->update(['failed_login_count' => 0]);

        return [
            'success' => true,
            'guard' => $guard,
        ];
    }

    /**
     * Issue a fresh guard session (access + refresh JWT) for an already
     * authenticated guard. Enforces the single-active-session rule via
     * createGuardSession() (any prior session is invalidated).
     *
     * @return array{access_token: string, refresh_token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function issueGuardSession(Guard $guard, array $deviceInfo): array
    {
        $guard->update(['last_login_at' => now()]);

        return $this->createGuardSession($guard, $deviceInfo);
    }

    /**
     * Ensure the guard has a per-guard HMAC secret and return it (raw).
     *
     * This is the shared symmetric key the app uses to sign photo-upload
     * payloads (spec §12.5); the server recomputes the HMAC with the same key.
     * It is generated once on first login and persisted raw (it must be
     * reproducible on both sides), hidden from serialization on the model, and
     * returned to the app exactly once here so it can be stored in the device
     * keychain. Returned on every login so a re-installed app can re-cache it.
     */
    public function ensureHmacSecret(Guard $guard): string
    {
        if (empty($guard->hmac_secret)) {
            $guard->forceFill(['hmac_secret' => bin2hex(random_bytes(32))]);
            $guard->save();
        }

        return $guard->hmac_secret;
    }

    /**
     * Mint a new access token for an existing, validated session (refresh
     * flow). Rolls the session's stored access-token hash + expiry forward so
     * the new access token passes GuardAuth; the refresh token is unchanged.
     *
     * @return array{access_token: string, expires_at: \Illuminate\Support\Carbon}
     */
    public function refreshAccessToken(Guard $guard, string $sessionId): array
    {
        $accessToken = $this->generateJWT($guard, 'access', 2); // 2 hours
        $expiresAt = now()->addHours(2);

        DB::table('guard_sessions')
            ->where('id', $sessionId)
            ->update([
                'access_token_hash' => hash('sha256', $accessToken),
                'expires_at' => $expiresAt,
            ]);

        return [
            'access_token' => $accessToken,
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * Create admin session.
     */
    private function createAdminSession(Admin $admin): string
    {
        // Enforce a single active session per admin: invalidate any prior
        // sessions before issuing a new one (mirrors createGuardSession()).
        DB::table('admin_sessions')
            ->where('admin_id', $admin->id)
            ->delete();

        $sessionId = Str::uuid();

        // Generate session token for web sessions
        $sessionToken = Str::random(64);

        // Store in database
        DB::table('admin_sessions')->insert([
            'id' => $sessionId,
            'admin_id' => $admin->id,
            'access_token_hash' => hash('sha256', $sessionToken),
            'refresh_token_hash' => hash('sha256', $sessionToken . '_refresh'),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
            'expires_at' => now()->addHours(8), // 8-hour session
        ]);

        // Store in PHP session
        session([
            'admin_authenticated' => true,
            'admin_id' => $admin->id,
            'admin_name' => $admin->name,
            'admin_session_id' => $sessionId,
            'admin_session_token' => $sessionToken
        ]);

        return $sessionId;
    }

    /**
     * Create guard session with JWT tokens.
     */
    private function createGuardSession(Guard $guard, array $deviceInfo): array
    {
        // Invalidate any existing session
        DB::table('guard_sessions')
            ->where('guard_id', $guard->id)
            ->delete();

        $sessionId = Str::uuid();
        $accessToken = $this->generateJWT($guard, 'access', 2); // 2 hours
        $refreshToken = $this->generateJWT($guard, 'refresh', 168); // 7 days
        $expiresAt = now()->addHours(2);

        // Store session in database
        DB::table('guard_sessions')->insert([
            'id' => $sessionId,
            'guard_id' => $guard->id,
            'access_token_hash' => hash('sha256', $accessToken),
            'refresh_token_hash' => hash('sha256', $refreshToken),
            'device_identifier' => $deviceInfo['device_id'] ?? null,
            'device_name' => $deviceInfo['device_name'] ?? null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'expires_at' => $expiresAt,
            'created_at' => now(),
        ]);

        // Update guard's active session
        $guard->update(['active_session_token_id' => $sessionId]);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_at' => $expiresAt
        ];
    }

    /**
     * Generate JWT token.
     */
    private function generateJWT(Guard $guard, string $type, int $hoursValid): string
    {
        $payload = [
            'iss' => config('app.name'),
            'sub' => $guard->id,
            'type' => $type,
            'iat' => now()->timestamp,
            'exp' => now()->addHours($hoursValid)->timestamp,
            'employee_code' => $guard->employee_code
        ];

        return JWT::encode($payload, config('app.key'), 'HS256');
    }

    /**
     * Decode and verify a guard JWT, returning the payload as an array.
     *
     * Unlike validateJWT() this does NOT swallow failures — it lets the
     * firebase/php-jwt exceptions propagate so callers (e.g. GuardAuth
     * middleware) can distinguish an EXPIRED token from a malformed/forged
     * one and respond with the correct error code (TOKEN_EXPIRED vs
     * TOKEN_INVALID). Throws:
     *   - Firebase\JWT\ExpiredException        (exp in the past)
     *   - Firebase\JWT\SignatureInvalidException (wrong APP_KEY / tampered)
     *   - Firebase\JWT\BeforeValidException, UnexpectedValueException, ...
     *
     * @throws \Exception
     */
    public function decodeGuardToken(string $token): array
    {
        $decoded = JWT::decode($token, new Key(config('app.key'), 'HS256'));
        return (array) $decoded;
    }

    /**
     * Validate JWT token.
     *
     * Lenient variant: returns the payload array on success, or null on any
     * failure. Use decodeGuardToken() when you need to know *why* it failed.
     */
    public function validateJWT(string $token): ?array
    {
        try {
            return $this->decodeGuardToken($token);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Logout admin.
     */
    public function logoutAdmin(): bool
    {
        $sessionId = session('admin_session_id');

        if ($sessionId) {
            DB::table('admin_sessions')
                ->where('id', $sessionId)
                ->delete();
        }

        session()->flush();
        return true;
    }

    /**
     * Logout guard.
     */
    public function logoutGuard(string $guardId): bool
    {
        DB::table('guard_sessions')
            ->where('guard_id', $guardId)
            ->delete();

        Guard::where('id', $guardId)
            ->update(['active_session_token_id' => null]);

        return true;
    }
}