<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Guards\Models\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class GuardController extends Controller
{
    /**
     * Display a listing of guards.
     */
    public function index(Request $request)
    {
        $query = Guard::query();

        // Search functionality
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhere('employee_code', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by employment status
        if ($request->filled('status')) {
            $query->where('employment_status', $request->status);
        }

        // Filter by SIA licence expiry
        if ($request->filled('sia_expiry')) {
            switch ($request->sia_expiry) {
                case 'expired':
                    $query->where('sia_licence_expiry', '<', now());
                    break;
                case 'expiring_soon':
                    $query->whereBetween('sia_licence_expiry', [now(), now()->addDays(30)]);
                    break;
                case 'valid':
                    $query->where('sia_licence_expiry', '>', now()->addDays(30));
                    break;
            }
        }

        $guards = $query->orderBy('first_name')->paginate(15);

        return view('admin.guards.index', compact('guards'));
    }


    /**
     * Store a newly created guard.
     */
    public function store(Request $request)
    {
        $validator = $this->validateGuard($request);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors occurred',
                    'errors' => $validator->errors()
                ], 422);
            }
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // Additional business validation
        $businessValidation = $this->validateBusinessRules($request);
        if (!$businessValidation['valid']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Business validation failed',
                    'errors' => ['business' => $businessValidation['errors']]
                ], 422);
            }
            return back()
                ->withErrors(['business' => $businessValidation['errors']])
                ->withInput();
        }

        // Create guard (employee-code generation + insert + audit log are
        // wrapped in a transaction so a failure cannot leave a partial record).
        try {
            $guard = DB::transaction(function () use ($request) {
                $guard = Guard::create([
                    'employee_code' => $this->generateEmployeeCode(),
                    'first_name' => $request->first_name,
                    'last_name' => $request->last_name,
                    'username' => $request->username,
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'phone' => $request->phone,
                    'sia_licence_number' => $request->sia_licence_number,
                    'sia_licence_expiry' => $request->sia_licence_expiry,
                    'sia_licence_type' => $request->sia_licence_type,
                    'hire_date' => $request->hire_date,
                    'employment_status' => $request->employment_status ?? 'active',
                    'status' => 'active',
                    'created_by' => $this->currentAdminId(),
                ]);

                // Log audit trail
                $this->logGuardAction('created', $guard, $request->all());

                return $guard;
            });
        } catch (\Exception $e) {
            Log::error('Guard creation failed', ['exception' => $e]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to create guard. Please try again.'
                ], 500);
            }

            return back()
                ->with('error', 'Unable to create guard. Please try again.')
                ->withInput();
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Guard {$guard->first_name} {$guard->last_name} created successfully",
                'guard' => $guard->fresh()
            ]);
        }

        return redirect()
            ->route('admin.guards.index')
            ->with('success', "Guard {$guard->first_name} {$guard->last_name} created successfully");
    }

    /**
     * Get guard data for editing (AJAX endpoint for drawer).
     */
    public function show(Guard $guard)
    {
        if (request()->expectsJson()) {
            return response()->json([
                'success' => true,
                'guard' => [
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
                    'hire_date' => $guard->hire_date?->format('Y-m-d'),
                    'last_login_at' => $guard->last_login_at?->toISOString(),
                ]
            ]);
        }

        // Fallback: redirect to guards index (no separate view page)
        return redirect()->route('admin.guards.index');
    }

    /**
     * Toggle guard status (activate/deactivate).
     */
    public function toggleStatus(Request $request, Guard $guard)
    {
        $action = $request->input('action'); // 'activate' or 'deactivate'

        if (!in_array($action, ['activate', 'deactivate'])) {
            return response()->json(['success' => false, 'error' => 'Invalid action'], 400);
        }

        $newStatus = $action === 'activate' ? 'active' : 'inactive';

        // Business validation for deactivation
        if ($action === 'deactivate' && $guard->employment_status === 'active') {
            $activeShifts = $guard->shifts()->where('status', 'active')->count();
            if ($activeShifts > 0) {
                return response()->json([
                    'success' => false,
                    'error' => 'Cannot deactivate guard with active shifts'
                ], 400);
            }
        }

        $guard->update(['employment_status' => $newStatus]);

        // Log audit trail
        $this->logGuardAction($action === 'activate' ? 'activated' : 'deactivated', $guard, [
            'action' => $action,
            'previous_status' => $guard->getOriginal('employment_status'),
            'new_status' => $newStatus
        ]);

        return response()->json([
            'success' => true,
            'message' => "Guard {$guard->first_name} {$guard->last_name} has been {$action}d",
            'new_status' => $newStatus
        ]);
    }

    /**
     * Update the specified guard.
     */
    public function update(Request $request, Guard $guard)
    {
        $validator = $this->validateGuard($request, $guard->id);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation errors occurred',
                    'errors' => $validator->errors()
                ], 422);
            }
            return back()
                ->withErrors($validator)
                ->withInput();
        }

        // Additional business validation
        $businessValidation = $this->validateBusinessRules($request, $guard);
        if (!$businessValidation['valid']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Business validation failed',
                    'errors' => ['business' => $businessValidation['errors']]
                ], 422);
            }
            return back()
                ->withErrors(['business' => $businessValidation['errors']])
                ->withInput();
        }

        $originalData = $guard->toArray();

        // Update guard
        $updateData = [
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'username' => $request->username,
            'email' => $request->email,
            'phone' => $request->phone,
            'sia_licence_number' => $request->sia_licence_number,
            'sia_licence_expiry' => $request->sia_licence_expiry,
            'sia_licence_type' => $request->sia_licence_type,
            'hire_date' => $request->hire_date,
            'employment_status' => $request->employment_status,
        ];

        // Only update password if provided
        if ($request->filled('password')) {
            $updateData['password'] = Hash::make($request->password);
        }

        $guard->update($updateData);

        // Log audit trail
        $this->logGuardAction('updated', $guard, [
            'original' => $originalData,
            'updated' => $request->all()
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Guard {$guard->first_name} {$guard->last_name} updated successfully",
                'guard' => $guard->fresh()
            ]);
        }

        return redirect()
            ->route('admin.guards.index')
            ->with('success', "Guard {$guard->first_name} {$guard->last_name} updated successfully");
    }

    /**
     * Delete the specified guard (GDPR compliant - removes personal data, preserves audit trails).
     */
    public function destroy(Request $request, Guard $guard)
    {
        // Business validation - check for active shifts
        $activeShifts = $guard->shifts()->where('status', 'active')->count();
        if ($activeShifts > 0) {
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Cannot delete guard with active shifts. Please end all shifts first.'
                ], 400);
            }
            return redirect()
                ->route('admin.guards.index')
                ->with('error', 'Cannot delete guard with active shifts. Please end all shifts first.');
        }

        $guardName = "{$guard->first_name} {$guard->last_name}";
        $guardId = $guard->id;

        // Log deletion action BEFORE deletion (for audit trail)
        $this->logGuardAction('deleted', $guard, [
            'deletion_reason' => 'GDPR deletion request',
            'deleted_by' => session('admin_id'),
            'personal_data_removed' => true,
            'audit_trails_preserved' => true,
            'deletion_timestamp' => now()->toISOString()
        ]);

        try {
            // GDPR-compliant deletion: Remove personal data but preserve audit trails.
            // Re-check for active shifts inside the transaction to avoid a race
            // where a shift becomes active between the initial check and delete.
            DB::transaction(function () use ($guard) {
                $activeShifts = $guard->shifts()->where('status', 'active')->lockForUpdate()->count();
                if ($activeShifts > 0) {
                    throw new \RuntimeException('Guard has active shifts');
                }

                // Foreign key constraints with CASCADE handle related data appropriately.
                $guard->delete();
            });

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => "Guard {$guardName} has been permanently deleted (GDPR compliant)"
                ]);
            }

            return redirect()
                ->route('admin.guards.index')
                ->with('success', "Guard {$guardName} has been permanently deleted (GDPR compliant)");

        } catch (\Exception $e) {
            // Log the error
            logger()->error('Guard deletion failed', [
                'guard_id' => $guardId,
                'guard_name' => $guardName,
                'error' => $e->getMessage(),
                'admin_id' => session('admin_id')
            ]);

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Failed to delete guard. Please contact system administrator.'
                ], 500);
            }

            return redirect()
                ->route('admin.guards.index')
                ->with('error', 'Failed to delete guard. Please contact system administrator.');
        }
    }

    /**
     * Validate guard input.
     */
    private function validateGuard(Request $request, ?string $guardId = null): \Illuminate\Validation\Validator
    {
        $rules = [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'username' => [
                'required',
                'string',
                'max:255',
                'regex:/^[a-zA-Z0-9._-]+$/',
                Rule::unique('guards')->ignore($guardId)
            ],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('guards')->ignore($guardId)
            ],
            'phone' => 'required|string|max:20',
            'sia_licence_number' => [
                'required',
                'string',
                'max:50',
                'regex:/^[0-9]{8}$/', // SIA licence format
                Rule::unique('guards')->ignore($guardId)
            ],
            'sia_licence_expiry' => 'required|date|after:today',
            'sia_licence_type' => 'required|in:Door Supervision,Security Guarding,Public Space Surveillance,Key Holding',
            'hire_date' => 'required|date|before_or_equal:today',
            'employment_status' => 'required|in:active,inactive,suspended',
        ];

        // Password rules (required for new guards, optional for updates)
        if (!$guardId) {
            $rules['password'] = 'required|string|min:8|confirmed';
        } else {
            $rules['password'] = 'nullable|string|min:8|confirmed';
        }

        return Validator::make($request->all(), $rules, [
            'sia_licence_number.regex' => 'SIA licence number must be 8 digits',
            'username.regex' => 'Username can only contain letters, numbers, dots, underscores and hyphens',
            'sia_licence_expiry.after' => 'SIA licence must not be expired',
        ]);
    }

    /**
     * Validate business rules (Working Time Regulations, etc.).
     */
    private function validateBusinessRules(Request $request, ?Guard $guard = null): array
    {
        $errors = [];

        // SIA Licence validation
        if ($request->filled('sia_licence_expiry')) {
            $expiryDate = \Carbon\Carbon::parse($request->sia_licence_expiry);

            if ($expiryDate->isPast()) {
                $errors[] = 'SIA licence cannot be expired';
            } elseif ($expiryDate->diffInDays() <= 30) {
                // Warning, not error
                session()->flash('warning', 'SIA licence expires within 30 days');
            }
        }

        // Employment status validation
        if ($guard && $guard->employment_status === 'active' && $request->employment_status !== 'active') {
            // Check for active shifts
            $activeShifts = $guard->shifts()->where('status', 'active')->count();
            if ($activeShifts > 0) {
                $errors[] = 'Cannot change employment status while guard has active shifts';
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }

    /**
     * Generate unique employee code.
     */
    private function generateEmployeeCode(): string
    {
        do {
            $code = 'GRD' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        } while (Guard::where('employee_code', $code)->exists());

        return $code;
    }

    /**
     * Get guards list for dropdowns/selects.
     */
    public function list()
    {
        $guards = Guard::where('employment_status', 'active')
            ->select('id', 'first_name', 'last_name', 'username', 'employment_status')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        return response()->json([
            'success' => true,
            'guards' => $guards
        ]);
    }

    /**
     * Log guard actions for audit trail.
     */
    private function logGuardAction(string $action, Guard $guard, array $data): void
    {
        logger("Guard {$action}", [
            'action' => $action,
            'guard_id' => $guard->id,
            'guard_name' => "{$guard->first_name} {$guard->last_name}",
            'admin_id' => session('admin_id'),
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'data' => $data,
            'timestamp' => now()->toISOString()
        ]);
    }
}
