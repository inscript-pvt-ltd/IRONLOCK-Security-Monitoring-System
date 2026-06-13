<?php

namespace App\Domains\Authentication\Services;

use App\Domains\Admins\Models\Admin;
use App\Domains\Guards\Models\Guard;
use Illuminate\Support\Facades\Hash;

class AuthService
{
    /** Verify admin credentials (web dashboard). Returns the Admin or null. */
    public function verifyAdmin(string $email, string $password): ?Admin
    {
        $admin = Admin::where('email', $email)->where('status', 'active')->first();

        if ($admin && Hash::check($password, $admin->password)) {
            return $admin;
        }
        return null;
    }

    /**
     * Verify guard credentials (mobile API). The caller (SessionService) is
     * responsible for shift-window enforcement, JWT issuance, refresh-token
     * rotation, single-session invalidation, and rate-limiting / lockout.
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