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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
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
        $shifts = Shift::with(['assignedGuard', 'site', 'geofence', 'creator'])
            ->when($request->get('status'), function($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->get('guard_id'), function($query, $guardId) {
                $query->where('guard_id', $guardId);
            })
            ->when($request->get('site_id'), function($query, $siteId) {
                $query->where('site_id', $siteId);
            })
            ->when($request->get('date_from'), function($query, $dateFrom) {
                $query->where('scheduled_start', '>=', Carbon::parse($dateFrom)->startOfDay());
            })
            ->when($request->get('date_to'), function($query, $dateTo) {
                $query->where('scheduled_end', '<=', Carbon::parse($dateTo)->endOfDay());
            })
            ->orderBy('scheduled_start', 'desc')
            ->paginate(20);

        return view('admin.shifts.index', compact('shifts'));
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
                $overrideNeeded = [];
                $requiresOverride = false;

                foreach ($wtrValidation['violations'] as $violation) {
                    switch ($violation['type']) {
                        case 'DURATION_16HR_BLOCK':
                            return response()->json([
                                'success' => false,
                                'error' => 'Shift duration exceeds 16 hours maximum. This cannot be overridden.',
                                'wtr_violation' => $violation
                            ], 422);

                        case 'DURATION_12HR_WARNING':
                            if (!$request->boolean('override_12hr_warning')) {
                                $overrideNeeded[] = '12-hour duration warning';
                                $requiresOverride = true;
                            }
                            break;

                        case 'REST_PERIOD_11HR':
                            if (!$request->boolean('override_11hr_rest')) {
                                $overrideNeeded[] = '11-hour rest period';
                                $requiresOverride = true;
                            }
                            break;
                    }
                }

                if ($requiresOverride) {
                    return response()->json([
                        'success' => false,
                        'wtr_warning' => true,
                        'violations' => $wtrValidation['violations'],
                        'override_required' => $overrideNeeded,
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
                'created_by' => Auth::guard('admin')->user()->id,
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
            return response()->json([
                'success' => false,
                'error' => 'Error creating shift: ' . $e->getMessage()
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
                'scheduled_start' => 'required|date',
                'scheduled_end' => 'required|date|after:scheduled_start',
                'override_justification' => 'nullable|string|max:500'
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $scheduledStart = Carbon::parse($request->scheduled_start);
            $scheduledEnd = Carbon::parse($request->scheduled_end);

            // Re-validate WTR
            $wtrValidation = $this->validateWorkingTimeRegulations(
                $shift->guard_id,
                $scheduledStart,
                $scheduledEnd,
                $shift->id // Exclude current shift from conflict checking
            );

            if (!$wtrValidation['compliant'] && !$request->override_justification) {
                return response()->json([
                    'success' => false,
                    'wtr_warning' => true,
                    'violations' => $wtrValidation['violations'],
                    'message' => 'WTR compliance issues detected. Justification required for update.'
                ], 422);
            }

            $shift->update([
                'scheduled_start' => $scheduledStart,
                'scheduled_end' => $scheduledEnd,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Shift updated successfully',
                'shift' => $shift->load('assignedGuard', 'site', 'geofence')
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error updating shift: ' . $e->getMessage()
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
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Error cancelling shift: ' . $e->getMessage()
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
            'scheduled_end' => 'required|date|after:scheduled_start'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $wtrValidation = $this->validateWorkingTimeRegulations(
            $request->guard_id,
            Carbon::parse($request->scheduled_start),
            Carbon::parse($request->scheduled_end)
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
        $duration = $scheduledStart->diffInHours($scheduledEnd, true);

        // Rule 1: 16-hour absolute maximum (cannot be overridden)
        if ($duration > 16) {
            $violations[] = [
                'type' => 'DURATION_16HR_BLOCK',
                'severity' => 'ERROR',
                'message' => "Shift duration of {$duration} hours exceeds 16-hour maximum",
                'override_allowed' => false
            ];
        }

        // Rule 2: 12-hour warning (can be overridden with justification)
        if ($duration > 12) {
            $violations[] = [
                'type' => 'DURATION_12HR_WARNING',
                'severity' => 'WARNING',
                'message' => "Shift duration of {$duration} hours exceeds 12-hour recommendation",
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
                $violations[] = [
                    'type' => 'REST_PERIOD_11HR',
                    'severity' => 'WARNING',
                    'message' => "Only {$restPeriodHours} hours rest since last shift. 11 hours required.",
                    'override_allowed' => true,
                    'last_shift_end' => $lastShift->scheduled_end
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
     * Check for shift conflicts.
     */
    private function checkShiftConflicts(string $guardId, Carbon $start, Carbon $end, ?string $excludeShiftId = null): array
    {
        $conflicts = Shift::where('guard_id', $guardId)
            ->where('status', '!=', 'cancelled')
            ->when($excludeShiftId, function($query) use ($excludeShiftId) {
                $query->where('id', '!=', $excludeShiftId);
            })
            ->where(function($query) use ($start, $end) {
                $query->whereBetween('scheduled_start', [$start, $end])
                    ->orWhereBetween('scheduled_end', [$start, $end])
                    ->orWhere(function($q) use ($start, $end) {
                        $q->where('scheduled_start', '<=', $start)
                          ->where('scheduled_end', '>=', $end);
                    });
            })
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
        $adminId = Auth::guard('admin')->user()->id;

        foreach ($violations as $violation) {
            if ($violation['override_allowed']) {
                WorkingTimeOverride::create([
                    'shift_id' => $shift->id,
                    'override_type' => $violation['type'] === 'DURATION_12HR_WARNING' ? 'duration_12hr' : 'rest_period_11hr',
                    'justification' => $justification,
                    'approved_by' => $adminId,
                    'approved_at' => Carbon::now(),
                ]);
            }
        }
    }
}
