<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Domains\Shifts\Models\Shift;
use App\Domains\Guards\Models\Guard;
use App\Domains\Sites\Models\Site;
use App\Domains\Geofences\Models\Geofence;
use App\Domains\Shifts\Models\WorkingTimeOverride;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Carbon\Carbon;

/**
 * ShiftController - Shift Management with UK Working Time Regulations Compliance
 *
 * Handles shift scheduling, assignment, and lifecycle management with:
 * - Working Time Regulations 1998 compliance validation
 * - 12-hour warning, 16-hour hard limit enforcement
 * - 11-hour rest period validation
 * - Override management with documented justification
 * - Guard assignment to sites with geofence integration
 * - Real-time shift status tracking
 */
class ShiftController extends Controller
{
    /**
     * Display shifts with filtering and search.
     */
    public function index(Request $request)
    {
        // Validate filter inputs up front so malformed values (e.g. a bad
        // date string passed to Carbon::parse) cannot trigger an unhandled 500.
        $validator = Validator::make($request->all(), [
            'status' => 'nullable|in:scheduled,active,completed,cancelled',
            'guard_id' => 'nullable|uuid',
            'site_id' => 'nullable|uuid',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }
            return redirect()
                ->route('admin.shifts.index')
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $query = Shift::with(['assignedGuard', 'site', 'geofence', 'creator', 'workingTimeOverrides.approvedBy'])
                ->when($request->get('status'), function($q, $v) {
                    $q->where('status', $v);
                })
                ->when($request->get('guard_id'), function($q, $v) {
                    $q->where('guard_id', $v);
                })
                ->when($request->get('site_id'), function($q, $v) {
                    $q->where('site_id', $v);
                })
                ->when($request->get('date_from'), function($q, $v) {
                    $q->where('scheduled_start', '>=', Carbon::parse($v)->startOfDay());
                })
                ->when($request->get('date_to'), function($q, $v) {
                    $q->where('scheduled_end', '<=', Carbon::parse($v)->endOfDay());
                })
                ->orderBy('scheduled_start', 'asc');

            // AJAX calls from the calendar JS (Accept: application/json) get a
            // flat array of all matching shifts so the calendar can render them.
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'shifts' => $query->get(),
                ]);
            }

            $shifts = $query->paginate(20)->withQueryString();

            return view('admin.shifts.index', compact('shifts'));
        } catch (\Exception $e) {
            Log::error('Error loading shifts list', ['exception' => $e]);

            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'error' => 'Unable to load shifts.'], 500);
            }

            return redirect()
                ->route('admin.dashboard')
                ->with('error', 'Unable to load shifts. Please try again.');
        }
    }

    /**
     * Store a new shift with WTR validation.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'guard_id' => 'required|exists:guards,id',
            'site_id' => 'required|exists:sites,id',
            'scheduled_start' => 'required|date|after:now',
            'scheduled_end' => 'required|date|after:scheduled_start',
            'override_12hr_warning' => 'nullable|boolean',
            'override_11hr_rest' => 'nullable|boolean',
            'override_justification' => 'required_if:override_12hr_warning,true|required_if:override_11hr_rest,true|nullable|string|max:500'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();

        try {
            $guard = Guard::findOrFail($request->guard_id);
            $site = Site::findOrFail($request->site_id);

            // Get active geofence for the site
            $geofence = Geofence::where('site_id', $site->id)
                ->where('is_active', true)
                ->first();

            if (!$geofence) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active geofence found for the selected site. Please set up a geofence first.',
                    'redirect' => '/admin/sites'
                ], 422);
            }

            $scheduledStart = Carbon::parse($request->scheduled_start);
            $scheduledEnd = Carbon::parse($request->scheduled_end);

            // Working Time Regulations Validation
            $wtrValidation = $this->validateWorkingTimeRegulations(
                $guard->id,
                $scheduledStart,
                $scheduledEnd
            );

            // Handle WTR violations
            if (!$wtrValidation['compliant']) {
                $assessment = $this->assessWtrViolations(
                    $wtrValidation,
                    $request->boolean('override_12hr_warning'),
                    $request->boolean('override_11hr_rest')
                );

                // 16-hour maximum is a hard block — never overridable (spec rule #22).
                if ($assessment['blocked']) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Shift duration exceeds 16 hours maximum. This cannot be overridden.',
                        'wtr_violation' => $assessment['block_violation']
                    ], 422);
                }

                if (!empty($assessment['override_required'])) {
                    return response()->json([
                        'success' => false,
                        'wtr_warning' => true,
                        'violations' => $wtrValidation['violations'],
                        'override_required' => $assessment['override_required'],
                        'message' => 'Working Time Regulations compliance issues detected. Override required with justification.'
                    ], 422);
                }
            }

            // Check for shift conflicts
            $conflicts = $this->checkShiftConflicts($guard->id, $scheduledStart, $scheduledEnd);
            if (!empty($conflicts)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Shift conflicts detected with existing shifts',
                    'conflicts' => $conflicts
                ], 422);
            }

            // Create the shift
            $shift = Shift::create([
                'guard_id' => $guard->id,
                'site_id' => $site->id,
                'geofence_id' => $geofence->id,
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
                'status' => 'scheduled',
                'created_by' => $this->currentAdminId(),
            ]);

            // Create WTR overrides if needed
            if (($request->boolean('override_12hr_warning') || $request->boolean('override_11hr_rest')) && $request->override_justification) {
                $this->createWorkingTimeOverrides($shift, $wtrValidation['violations'], $request->override_justification);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Shift scheduled successfully',
                'shift' => $shift->load('assignedGuard', 'site', 'geofence'),
                'wtr_compliance' => $wtrValidation
            ]);
        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Error creating shift', ['exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to create shift. Please try again.'
            ], 500);
        }
    }

    /**
     * Display shift details.
     */
    public function show(string $id): JsonResponse
    {
        try {
            $shift = Shift::with([
                'assignedGuard',
                'site',
                'geofence',
                'creator',
                'workingTimeOverrides.approvedBy'
            ])->findOrFail($id);

            return response()->json([
                'success' => true,
                'shift' => $shift
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Shift not found'
            ], 404);
        }
    }

    /**
     * Update shift (with WTR re-validation).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
            $shift = Shift::findOrFail($id);

            // Prevent updating active or completed shifts
            if (in_array($shift->status, ['active', 'completed'])) {
                return response()->json([
                    'success' => false,
                    'error' => 'Cannot modify ' . $shift->status . ' shifts'
                ], 422);
            }

            $validator = Validator::make($request->all(), [
                'guard_id' => 'required|exists:guards,id',
                'site_id' => 'required|exists:sites,id',
                'scheduled_start' => 'required|date|after:now',
                'scheduled_end' => 'required|date|after:scheduled_start',
                'override_12hr_warning' => 'nullable|boolean',
                'override_11hr_rest' => 'nullable|boolean',
                'override_justification' => 'required_if:override_12hr_warning,true|required_if:override_11hr_rest,true|nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            // The guard and/or site may have been reassigned in the edit form, so
            // resolve them from the request rather than the existing record.
            $guard = Guard::findOrFail($request->guard_id);
            $site = Site::findOrFail($request->site_id);

            // A reassigned site needs its own active geofence; without one the
            // shift could not be monitored, so block the change just like store().
            $geofence = Geofence::where('site_id', $site->id)
                ->where('is_active', true)
                ->first();

            if (!$geofence) {
                return response()->json([
                    'success' => false,
                    'error' => 'No active geofence found for the selected site. Please set up a geofence first.',
                    'redirect' => '/admin/sites'
                ], 422);
            }

            $scheduledStart = Carbon::parse($request->scheduled_start);
            $scheduledEnd = Carbon::parse($request->scheduled_end);

            // Re-validate WTR for the (possibly reassigned) guard, excluding this
            // shift from the rest-period lookup so it never clashes with itself.
            $wtrValidation = $this->validateWorkingTimeRegulations(
                $guard->id,
                $scheduledStart,
                $scheduledEnd,
                $shift->id
            );

            // Apply the same WTR rules as store(): the 16-hour maximum is a hard
            // block that no justification can override; the 12-hour and 11-hour
            // rest warnings require the matching override flag + justification.
            if (!$wtrValidation['compliant']) {
                $assessment = $this->assessWtrViolations(
                    $wtrValidation,
                    $request->boolean('override_12hr_warning'),
                    $request->boolean('override_11hr_rest')
                );

                if ($assessment['blocked']) {
                    return response()->json([
                        'success' => false,
                        'error' => 'Shift duration exceeds 16 hours maximum. This cannot be overridden.',
                        'wtr_violation' => $assessment['block_violation']
                    ], 422);
                }

                if (!empty($assessment['override_required'])) {
                    return response()->json([
                        'success' => false,
                        'wtr_warning' => true,
                        'violations' => $wtrValidation['violations'],
                        'override_required' => $assessment['override_required'],
                        'message' => 'Working Time Regulations compliance issues detected. Override required with justification.'
                    ], 422);
                }
            }

            // Check for conflicts against the (possibly reassigned) guard's other
            // shifts, excluding this shift itself.
            $conflicts = $this->checkShiftConflicts($guard->id, $scheduledStart, $scheduledEnd, $shift->id);
            if (!empty($conflicts)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Shift conflicts detected with existing shifts',
                    'conflicts' => $conflicts
                ], 422);
            }

            DB::beginTransaction();
            try {
                $shift->update([
                    'guard_id' => $guard->id,
                    'site_id' => $site->id,
                    'geofence_id' => $geofence->id,
                    'scheduled_start' => $scheduledStart,
                    'scheduled_end' => $scheduledEnd,
                ]);

                // Reconcile override records to match the shift's current
                // violations: acknowledged warnings get an up-to-date row, and
                // any override that no longer applies is pruned. Called
                // unconditionally so an edit back to a compliant schedule clears
                // previously-logged overrides.
                $this->createWorkingTimeOverrides(
                    $shift,
                    $wtrValidation['violations'],
                    (string) ($request->override_justification ?? '')
                );

                DB::commit();
            } catch (\Throwable $e) {
                DB::rollBack();
                throw $e;
            }

            return response()->json([
                'success' => true,
                'message' => 'Shift updated successfully',
                'shift' => $shift->load('assignedGuard', 'site', 'geofence')
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Shift not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error updating shift', ['shift_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to update shift. Please try again.'
            ], 500);
        }
    }

    /**
     * Cancel a scheduled shift.
     */
    public function cancel(string $id): JsonResponse
    {
        try {
            $shift = Shift::findOrFail($id);

            if ($shift->status !== 'scheduled') {
                return response()->json([
                    'success' => false,
                    'error' => 'Can only cancel scheduled shifts'
                ], 422);
            }

            $shift->update(['status' => 'cancelled']);

            return response()->json([
                'success' => true,
                'message' => 'Shift cancelled successfully'
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'error' => 'Shift not found'
            ], 404);
        } catch (\Exception $e) {
            Log::error('Error cancelling shift', ['shift_id' => $id, 'exception' => $e]);
            return response()->json([
                'success' => false,
                'error' => 'Unable to cancel shift. Please try again.'
            ], 500);
        }
    }

    /**
     * Get WTR compliance check for shift planning.
     */
    public function checkWTRCompliance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'guard_id' => 'required|exists:guards,id',
            'scheduled_start' => 'required|date',
            'scheduled_end' => 'required|date|after:scheduled_start',
            'shift_id' => 'nullable|uuid|exists:shifts,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // When editing, exclude the shift itself from the rest-period lookup so
        // it is never compared against its own (pre-edit) database record. This
        // keeps the live preview consistent with what update() will actually do.
        $wtrValidation = $this->validateWorkingTimeRegulations(
            $request->guard_id,
            Carbon::parse($request->scheduled_start),
            Carbon::parse($request->scheduled_end),
            $request->input('shift_id')
        );

        return response()->json([
            'success' => true,
            'wtr_compliance' => $wtrValidation
        ]);
    }

    /**
     * Validate Working Time Regulations for a proposed shift.
     *
     * @param string $guardId
     * @param Carbon $scheduledStart
     * @param Carbon $scheduledEnd
     * @param string|null $excludeShiftId
     * @return array
     */
    private function validateWorkingTimeRegulations(string $guardId, Carbon $scheduledStart, Carbon $scheduledEnd, ?string $excludeShiftId = null): array
    {
        $violations = [];
        $duration = $scheduledStart->diffInHours($scheduledEnd, true); // float, used for comparisons

        // Human-readable duration label: "15 hours and 1 min" instead of "15.016667 hours".
        $totalMins = (int) round($scheduledStart->diffInMinutes($scheduledEnd));
        $dHours = intdiv($totalMins, 60);
        $dMins  = $totalMins % 60;
        $durationLabel = $dMins > 0 ? "{$dHours}h {$dMins}min" : "{$dHours}h";

        // Rule 1: 16-hour absolute maximum (cannot be overridden)
        if ($duration > 16) {
            $violations[] = [
                'type' => 'DURATION_16HR_BLOCK',
                'severity' => 'ERROR',
                'message' => "Shift duration of {$durationLabel} exceeds the 16-hour maximum and cannot be saved.",
                'override_allowed' => false
            ];
        }

        // Rule 2: 12-hour warning (can be overridden with justification)
        if ($duration > 12) {
            $violations[] = [
                'type' => 'DURATION_12HR_WARNING',
                'severity' => 'WARNING',
                'message' => "Shift duration of {$durationLabel} exceeds the 12-hour recommendation.",
                'override_allowed' => true
            ];
        }

        // Rule 3: 11-hour rest period validation
        $lastShift = Shift::where('guard_id', $guardId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeShiftId, function($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->where('scheduled_end', '<', $scheduledStart)
            ->orderBy('scheduled_end', 'desc')
            ->first();

        if ($lastShift) {
            $restPeriodHours = Carbon::parse($lastShift->scheduled_end)->diffInHours($scheduledStart, true);
            if ($restPeriodHours < 11) {
                $restMins  = (int) round(Carbon::parse($lastShift->scheduled_end)->diffInMinutes($scheduledStart));
                $rHours    = intdiv($restMins, 60);
                $rMinLabel = ($restMins % 60) > 0 ? "{$rHours}h " . ($restMins % 60) . "min" : "{$rHours}h";
                $violations[] = [
                    'type' => 'REST_PERIOD_11HR',
                    'severity' => 'WARNING',
                    'message' => "Only {$rMinLabel} rest since last shift — 11 hours required.",
                    'override_allowed' => true,
                    'last_shift_end' => $lastShift->scheduled_end
                ];
            }
        }

        // Rule 3b: 11-hour rest before the NEXT shift. Scheduling a shift that
        // ends too close to an already-scheduled later shift is just as much a
        // rest-period breach as the backward case, so check both directions.
        $nextShift = Shift::where('guard_id', $guardId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeShiftId, function($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->where('scheduled_start', '>=', $scheduledEnd)
            ->orderBy('scheduled_start', 'asc')
            ->first();

        if ($nextShift) {
            $restBeforeNext = $scheduledEnd->diffInHours(Carbon::parse($nextShift->scheduled_start), true);
            if ($restBeforeNext < 11) {
                $restMins  = (int) round($scheduledEnd->diffInMinutes(Carbon::parse($nextShift->scheduled_start)));
                $rHours    = intdiv($restMins, 60);
                $rMinLabel = ($restMins % 60) > 0 ? "{$rHours}h " . ($restMins % 60) . "min" : "{$rHours}h";
                $violations[] = [
                    'type' => 'REST_PERIOD_11HR',
                    'severity' => 'WARNING',
                    'message' => "Only {$rMinLabel} rest before the next shift — 11 hours required.",
                    'override_allowed' => true,
                    'next_shift_start' => $nextShift->scheduled_start
                ];
            }
        }

        return [
            'compliant' => empty($violations),
            'violations' => $violations,
            'duration_hours' => $duration,
            'validated_at' => Carbon::now()->toISOString()
        ];
    }

    /**
     * Assess WTR violations against the override flags supplied by the admin.
     *
     * Centralises the policy shared by store() and update():
     *  - DURATION_16HR_BLOCK is a hard block — never overridable (spec rule #22).
     *  - DURATION_12HR_WARNING / REST_PERIOD_11HR require the matching override
     *    flag (plus justification, enforced by validation) to proceed.
     *
     * @return array{blocked: bool, block_violation: ?array, override_required: array}
     */
    private function assessWtrViolations(array $wtrValidation, bool $override12hr, bool $override11hr): array
    {
        $blockViolation = null;
        $overrideRequired = [];

        foreach ($wtrValidation['violations'] as $violation) {
            switch ($violation['type']) {
                case 'DURATION_16HR_BLOCK':
                    $blockViolation = $violation;
                    break;

                case 'DURATION_12HR_WARNING':
                    if (!$override12hr) {
                        $overrideRequired[] = '12-hour duration warning';
                    }
                    break;

                case 'REST_PERIOD_11HR':
                    if (!$override11hr) {
                        $overrideRequired[] = '11-hour rest period';
                    }
                    break;
            }
        }

        return [
            'blocked' => $blockViolation !== null,
            'block_violation' => $blockViolation,
            // A backward- and forward-rest breach both map to the single
            // 11-hour-rest override, so collapse duplicates for a clean message.
            'override_required' => array_values(array_unique($overrideRequired)),
        ];
    }

    /**
     * Check for shift conflicts.
     */
    private function checkShiftConflicts(string $guardId, Carbon $start, Carbon $end, ?string $excludeShiftId = null): array
    {
        // Two half-open intervals [start, end) and [existingStart, existingEnd)
        // overlap iff existingStart < end AND existingEnd > start. Using strict
        // comparisons means shifts that merely touch at a boundary (one ends
        // exactly when the next begins) are NOT treated as a conflict, so
        // legitimate back-to-back scheduling is allowed.
        $conflicts = Shift::where('guard_id', $guardId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeShiftId, function($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->where('scheduled_start', '<', $end)
            ->where('scheduled_end', '>', $start)
            ->with('site')
            ->get();

        return $conflicts->map(function($shift) {
            return [
                'shift_id' => $shift->id,
                'site_name' => $shift->site->name,
                'start' => $shift->scheduled_start,
                'end' => $shift->scheduled_end,
                'status' => $shift->status
            ];
        })->toArray();
    }

    /**
     * Create working time overrides.
     */
    private function createWorkingTimeOverrides(Shift $shift, array $violations, string $justification): void
    {
        $adminId = $this->currentAdminId();
        $activeTypes = [];

        foreach ($violations as $violation) {
            if (!($violation['override_allowed'] ?? false)) {
                continue;
            }

            $overrideType = $violation['type'] === 'DURATION_12HR_WARNING'
                ? 'duration_12hr'
                : 'rest_period_11hr';
            $activeTypes[$overrideType] = true;

            // Keep a single override record per (shift, type). Re-saving an
            // edited shift refreshes the justification/admin/timestamp instead
            // of stacking duplicate rows for the same acknowledged warning.
            WorkingTimeOverride::updateOrCreate(
                [
                    'shift_id' => $shift->id,
                    'override_type' => $overrideType,
                ],
                [
                    'justification' => $justification,
                    'approved_by' => $adminId,
                    'approved_at' => Carbon::now(),
                ]
            );
        }

        // Drop any override rows whose warning no longer applies after an edit
        // (e.g. a rest-period override left behind once the shift was moved).
        WorkingTimeOverride::where('shift_id', $shift->id)
            ->when(!empty($activeTypes), function ($query) use ($activeTypes) {
                $query->whereNotIn('override_type', array_keys($activeTypes));
            })
            ->delete();
    }
}
