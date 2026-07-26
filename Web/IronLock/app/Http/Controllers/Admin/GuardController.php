<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Guards\Models\Guard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GuardController extends Controller
{
    /**
     * Display a listing of guards.
     */
    public function index(Request $request)
    {
        // Removed (archived) guards are hidden from the roster — their real
        // records stay in the DB so historical shifts still show their details.
        $query = Guard::query()->notErased();

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

        // Eager-load each guard's current active shift + its site so the roster
        // can show where the guard is right now without an N+1 per row.
        $guards = $query->with('activeShift.site')->orderBy('first_name')->paginate(15);

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
                    'email' => $request->email,
                    'password' => Hash::make($request->password),
                    'phone' => $request->phone,
                    'address' => $request->address,
                    'sia_licence_number' => $request->sia_licence_number,
                    'sia_licence_expiry' => $request->sia_licence_expiry,
                    'sia_licence_type' => $request->sia_licence_type,
                    'hire_date' => $request->hire_date,
                    'employment_status' => $request->employment_status ?? 'active',
                    'status' => 'active',
                    'created_by' => $this->currentAdminId(),
                ]);

                // Optional profile photo — stored only once we have the guard's
                // generated employee_code (part of the deterministic filename).
                if ($request->hasFile('photo')) {
                    $guard->update(['photo_path' => $this->storeGuardPhoto($request, $guard)]);
                }

                // Log audit trail (exclude the binary photo upload).
                $this->logGuardAction('created', $guard, $request->except('photo'));

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
                    'email' => $guard->email,
                    'phone' => $guard->phone,
                    'address' => $guard->address,
                    'photo_url' => $guard->photo_path ? route('admin.guards.photo', $guard->id) : null,
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
     * Return a guard's completed shifts for the "Recent Shifts" drawer (AJAX).
     *
     * Once a shift is completed it leaves the Active Shifts table on the shifts
     * page and surfaces here instead. Most recent first; the display-only
     * `reference` is shown while the UUID `id` drives the timeline link.
     */
    public function recentShifts(Guard $guard): \Illuminate\Http\JsonResponse
    {
        $shifts = $guard->shifts()
            ->where('status', 'completed')
            ->with('site:id,name')
            ->orderByDesc('scheduled_start')
            ->limit(50)
            ->get()
            ->map(function (\App\Domains\Shifts\Models\Shift $shift) {
                return [
                    'id' => $shift->id,
                    'reference' => $shift->reference,
                    'scheduled_start' => optional($shift->scheduled_start)->toISOString(),
                    'scheduled_end' => optional($shift->scheduled_end)->toISOString(),
                    'site_name' => $shift->site->name ?? null,
                    'timeline_url' => route('admin.shifts.timeline', $shift->id),
                ];
            });

        return response()->json([
            'success' => true,
            'guard' => [
                'id' => $guard->id,
                'name' => trim("{$guard->first_name} {$guard->last_name}"),
            ],
            'shifts' => $shifts,
        ]);
    }

    /**
     * Stream a guard's profile photo to an authenticated admin. The file lives
     * on the private `photos` disk (guards/…) and is never publicly served —
     * this route, behind the admin auth middleware, is the only way to view it,
     * mirroring how evidence photos are served (ShiftController::viewPhoto).
     */
    public function photo(Guard $guard)
    {
        if (empty($guard->photo_path) || !Storage::disk('photos')->exists($guard->photo_path)) {
            abort(404);
        }

        return Storage::disk('photos')->response($guard->photo_path);
    }

    /**
     * Store (or replace) a guard's uploaded profile photo on the private
     * `photos` disk and return its relative path.
     *
     * The filename is deterministic — slug(name) + employee_code — so a later
     * edit that re-uploads writes to the same conceptual slot. Any previously
     * stored file is deleted first, which also collects a stale name should the
     * guard have been renamed (a new slug) since the last upload. The caller
     * only invokes this when a file is actually present.
     */
    private function storeGuardPhoto(Request $request, Guard $guard): string
    {
        // Remove the prior file first so a replacement never leaves an orphan,
        // even when the slug changes because the guard was renamed.
        if (!empty($guard->photo_path)) {
            Storage::disk('photos')->delete($guard->photo_path);
        }

        $file = $request->file('photo');
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $base = Str::slug(trim($request->first_name . ' ' . $request->last_name)) ?: 'guard';
        $name = $base . '_' . $guard->employee_code . '.' . $ext;

        return $file->storeAs('guards', $name, 'photos');
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
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
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

        // Replace the profile photo only when a new file is supplied — the old
        // file is removed first so a replacement reuses the same slot and no
        // orphan lingers even if the guard's name changed. Absent a new file the
        // existing photo_path is left exactly as-is.
        if ($request->hasFile('photo')) {
            $updateData['photo_path'] = $this->storeGuardPhoto($request, $guard);
        }

        $guard->update($updateData);

        // Log audit trail
        $this->logGuardAction('updated', $guard, [
            'original' => $originalData,
            'updated' => $request->except('photo')
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
     * Remove a guard from the active roster (Phase 8 · SEC-004, revised) — a
     * data-preserving archive, NOT a hard delete or PII anonymisation.
     *
     * The guard's real details are kept so historical shift_events / alerts /
     * evidence / reports that reference them still show the real guard; the row
     * is only hidden from the roster + shift picker and the account is signed
     * out and deactivated. The heavy lifting is in GuardErasureService; this
     * only authorises, delegates and audits. Reversible.
     */
    public function erase(Request $request, Guard $guard, \App\Domains\Guards\Services\GuardErasureService $erasure)
    {
        $guardName = trim("{$guard->first_name} {$guard->last_name}");

        $result = $erasure->erase($guard);

        if (!$result['success']) {
            return response()->json(['success' => false, 'error' => $result['error']], 422);
        }

        $this->logGuardAction('removed', $guard, [
            'removal_reason' => 'Removed from active roster',
            'removed_by' => session('admin_id'),
            'data_preserved' => true,
            'audit_trails_preserved' => true,
            'removal_timestamp' => now()->toISOString(),
        ]);

        return response()->json([
            'success' => true,
            'message' => "{$guardName} has been removed. Their records are preserved for historical shifts.",
        ]);
    }

    /**
     * Validate guard input.
     */
    private function validateGuard(Request $request, ?string $guardId = null): \Illuminate\Validation\Validator
    {
        $rules = [
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('guards')->ignore($guardId)
            ],
            'phone' => 'required|string|max:20',
            // Optional postal address.
            'address' => 'nullable|string|max:500',
            // Optional profile photo (JPEG/PNG/WebP, up to 5 MB).
            'photo' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:5120',
            'sia_licence_number' => [
                'required',
                'string',
                'max:50',
                'regex:/^[0-9]{8}$/', // SIA licence format
                Rule::unique('guards')->ignore($guardId)
            ],
            'sia_licence_expiry' => 'required|date|after:today',
            // Free text so the admin can pick a preset OR enter a custom licence
            // type ("Other"). Kept required and length-bounded.
            'sia_licence_type' => 'required|string|max:100',
            'hire_date' => 'required|date|before_or_equal:today',
            'employment_status' => 'required|in:active,inactive,suspended',
        ];

        // Password rules — numeric-only (digits) so guards can enter it on a
        // mobile numeric keypad. Required for new guards, optional for updates.
        // The admin form generates the password (single field, no re-type), so
        // there is no `confirmed` rule / password_confirmation field any more.
        $passwordRule = ['string', 'min:8', 'regex:/^[0-9]+$/'];
        $rules['password'] = array_merge([$guardId ? 'nullable' : 'required'], $passwordRule);

        return Validator::make($request->all(), $rules, [
            'sia_licence_number.regex' => 'SIA licence number must be 8 digits',
            'sia_licence_expiry.after' => 'SIA licence must not be expired',
            'password.regex' => 'Password must contain numbers only (0-9).',
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
            ->notErased()
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
