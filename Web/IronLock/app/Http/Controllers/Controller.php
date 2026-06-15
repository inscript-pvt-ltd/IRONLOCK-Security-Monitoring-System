<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Resolve the currently authenticated admin's id.
     *
     * Admin auth is session-based (see AuthService::createAdminSession and the
     * AdminAuth middleware) — Laravel's Auth guard is never populated for
     * admins. All admin create/update paths should resolve the acting admin
     * through this single helper so identity is determined one consistent way.
     */
    protected function currentAdminId(): ?string
    {
        return session('admin_id');
    }
}
