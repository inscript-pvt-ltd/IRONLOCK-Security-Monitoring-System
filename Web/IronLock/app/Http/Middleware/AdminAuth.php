<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminAuth
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!session('admin_authenticated') || !session('admin_id')) {
            if ($request->expectsJson()) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            return redirect()->route('admin.login')
                ->with('error', 'Please log in to access the admin dashboard.');
        }

        // Add admin data to request for easy access
        $request->merge(['admin_id' => session('admin_id')]);

        return $next($request);
    }
}