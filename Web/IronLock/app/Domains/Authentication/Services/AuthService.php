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
     * Authenticate guard for mobile API.
     */
    public function authenticateGuard(string $username, string $password, array $deviceInfo): array
    {
        $guard = Guard::where('username', $username)
            ->where('employment_status', 'active')
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

        // Reset failed login count on success
        $guard->update([
            'failed_login_count' => 0,
            'last_login_at' => now()
        ]);

        // Create guard session and JWT
        $tokens = $this->createGuardSession($guard, $deviceInfo);

        return [
            'success' => true,
            'guard' => $guard,
            'access_token' => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'expires_at' => $tokens['expires_at']
        ];
    }

    /**
     * Create admin session.
     */
    private function createAdminSession(Admin $admin): string
    {
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
     * Validate JWT token.
     */
    public function validateJWT(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key(config('app.key'), 'HS256'));
            return (array) $decoded;
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

    /**
     * Legacy method - kept for backward compatibility
     */
    public function verifyAdmin(string $email, string $password): ?Admin
    {
        $admin = Admin::where('email', $email)->where('status', 'active')->first();

        if ($admin && Hash::check($password, $admin->password)) {
            return $admin;
        }
        return null;
    }

    /**
     * Legacy method - kept for backward compatibility
     */
    public function verifyGuard(string $username, string $password): ?Guard
    {
        $guard = Guard::where('username', $username)
            ->where('employment_status', 'active')
            ->whereNull('account_locked_at')
            ->first();

        if ($guard && Hash::check($password, $guard->password)) {
            return $guard;
        }
        return null;
    }
}