<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Shifts\Models\Shift;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile shift lifecycle — current shift, begin, end.
 *
 * Contract: Details/Important/MOBILE_API_INTEGRATION.md §5.5–5.7.
 * All routes are behind GuardAuth; the authenticated guard comes from
 * $this->currentGuard($request). The server owns the start/end window rules
 * (Shift::canStart()/canEnd()); the app follows the returned can_* flags.
 */
class ShiftController extends Controller
{
    use InteractsWithMobileApi;

    /**
     * GET /shifts/current — the guard's active shift, else the soonest
     * scheduled shift that hasn't passed its end. Returns shift = null when
     * there's nothing relevant (not an error).
     */
    public function current(Request $request): JsonResponse
    {
        $guard = $this->currentGuard($request);
        $now = now();

        $shift = Shift::with(['site', 'geofence'])
            ->where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_ACTIVE)
            ->orderBy('scheduled_start')
            ->first();

        if (!$shift) {
            $shift = Shift::with(['site', 'geofence'])
                ->where('guard_id', $guard->id)
                ->where('status', Shift::STATUS_SCHEDULED)
                ->where('scheduled_end', '>=', $now)
                ->orderBy('scheduled_start')
                ->first();
        }

        if (!$shift) {
            return $this->apiSuccess(['shift' => null]);
        }

        return $this->apiSuccess(['shift' => $this->shiftPayload($shift)]);
    }

    /**
     * POST /shifts/{id}/start — begin a scheduled shift.
     */
    public function start(Request $request, string $id): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $shift = Shift::find($id);

        if (!$shift) {
            return $this->apiError('NOT_FOUND', 'Shift not found.', 404);
        }

        if ($shift->guard_id !== $guard->id) {
            return $this->apiError('FORBIDDEN', 'This shift is not assigned to you.', 403);
        }

        if (!$shift->canStart()) {
            return $this->apiError('SHIFT_NOT_STARTABLE', 'This shift cannot be started right now.', 409);
        }

        $shift->start(); // status -> active, stamps actual_start (server clock)
        $shift->refresh();

        return $this->apiSuccess([
            'shift' => [
                'id' => $shift->id,
                'status' => $shift->status,
                'actual_start' => optional($shift->actual_start)->toISOString(),
                'can_end' => $shift->canEnd(),
            ],
        ]);
    }

    /**
     * POST /shifts/{id}/end — end an active shift.
     */
    public function end(Request $request, string $id): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $shift = Shift::find($id);

        if (!$shift) {
            return $this->apiError('NOT_FOUND', 'Shift not found.', 404);
        }

        if ($shift->guard_id !== $guard->id) {
            return $this->apiError('FORBIDDEN', 'This shift is not assigned to you.', 403);
        }

        if (!$shift->canEnd()) {
            return $this->apiError('SHIFT_NOT_ENDABLE', 'This shift is not active.', 409);
        }

        $shift->end(); // status -> completed, stamps actual_end + compliance summary
        $shift->refresh();

        return $this->apiSuccess([
            'shift' => [
                'id' => $shift->id,
                'status' => $shift->status,
                'actual_start' => optional($shift->actual_start)->toISOString(),
                'actual_end' => optional($shift->actual_end)->toISOString(),
                'duration_hours' => $shift->actual_duration !== null ? round($shift->actual_duration, 2) : null,
            ],
        ]);
    }

    /**
     * Shape a shift for GET /shifts/current (§5.5). can_start/can_end are
     * computed server-side from the window + status.
     */
    private function shiftPayload(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'status' => $shift->status,
            'scheduled_start' => optional($shift->scheduled_start)->toISOString(),
            'scheduled_end' => optional($shift->scheduled_end)->toISOString(),
            'actual_start' => optional($shift->actual_start)->toISOString(),
            'actual_end' => optional($shift->actual_end)->toISOString(),
            'can_start' => $shift->canStart(),
            'can_end' => $shift->canEnd(),
            'site' => $shift->site ? [
                'id' => $shift->site->id,
                'name' => $shift->site->name,
                'grace_period_minutes' => $shift->site->grace_period_minutes,
            ] : null,
            'geofence' => $shift->geofence ? [
                'id' => $shift->geofence->id,
                'name' => $shift->geofence->name,
                'coordinates' => $shift->geofence->getPolygonCoordinates(),
            ] : null,
        ];
    }
}
