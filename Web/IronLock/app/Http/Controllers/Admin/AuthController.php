<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Authentication\Services\AuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AuthController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    /**
     * Show the admin login form.
     */
    public function showLogin()
    {
        // Redirect if already authenticated
        if (session('admin_authenticated')) {
            return redirect()->route('admin.dashboard');
        }

        return view('admin.auth.login');
    }

    /**
     * Handle admin login.
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:8',
        ]);

        if ($validator->fails()) {
            return back()
                ->withErrors($validator)
                ->withInput($request->only('email'));
        }

        // Attempt authentication
        $result = $this->authService->authenticateAdmin(
            $request->email,
            $request->password,
            $request->ip()
        );

        if (!$result['success']) {
            return back()
                ->withErrors(['email' => $result['error']])
                ->withInput($request->only('email'));
        }

        // Log successful login
        logger('Admin login successful', [
            'admin_id' => $result['admin']->id,
            'email' => $result['admin']->email,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent()
        ]);

        return redirect()->route('admin.dashboard')
            ->with('success', 'Welcome to IronLock Admin Dashboard');
    }

    /**
     * Handle admin logout.
     */
    public function logout(Request $request)
    {
        $adminId = session('admin_id');

        // Logout through service
        $this->authService->logoutAdmin();

        // Log logout
        logger('Admin logout', [
            'admin_id' => $adminId,
            'ip_address' => $request->ip()
        ]);

        return redirect()->route('admin.login')
            ->with('success', 'You have been logged out successfully');
    }

    /**
     * Check authentication status (AJAX endpoint).
     */
    public function status()
    {
        return response()->json([
            'authenticated' => session('admin_authenticated', false),
            'admin_id' => session('admin_id'),
            'session_expires_at' => session()->get('admin_session_expires_at')
        ]);
    }
}
