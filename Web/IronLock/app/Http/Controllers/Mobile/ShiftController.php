<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

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
     * scheduled/checked-in shift that hasn't passed its end. Missed shifts are
     * excluded (they require supervisor resolution). Returns shift = null when
     * there's nothing relevant (not an error).
     */
    public function current(Request $request): JsonResponse
    {
        \Log::info('Mobile Shifts Current Request', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
            'ip'      => $request->ip(),
        ]);

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
                ->whereIn('status', [Shift::STATUS_SCHEDULED, Shift::STATUS_CHECKED_IN])
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
        \Log::info('Mobile Shift Start Request', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
            'ip'      => $request->ip(),
        ]);

        $guard = $this->currentGuard($request);

        $shift = Shift::find($id);

        if (!$shift) {
            return $this->apiError('NOT_FOUND', 'Shift not found.', 404);
        }

        if ($shift->guard_id !== $guard->id) {
            return $this->apiError('FORBIDDEN', 'This shift is not assigned to you.', 403);
        }

        if (!$shift->canStart()) {
            // Distinguish an expired window (the spec's "contact supervisor"
            // case) from a generic not-startable state, and record the failed
            // attempt for the attendance audit either way.
            if ($shift->windowHasExpired()) {
                $this->logShiftEvent($shift, 'START_WINDOW_EXPIRED', [
                    'scheduled_start' => optional($shift->scheduled_start)->toISOString(),
                    'status' => $shift->status,
                ]);

                return $this->apiError(
                    'START_WINDOW_EXPIRED',
                    'Your shift start window has expired. Please contact your supervisor to initiate the shift.',
                    409
                );
            }

            return $this->apiError('SHIFT_NOT_STARTABLE', 'This shift cannot be started right now.', 409);
        }

        $shift->start(); // status -> active, stamps actual_start (server clock)
        $shift->refresh();

        $this->logShiftEvent($shift, 'SHIFT_STARTED', [
            'actual_start' => optional($shift->actual_start)->toISOString(),
            'late' => $shift->actual_start
                && $shift->actual_start->greaterThan($shift->scheduled_start->copy()->addMinutes(Shift::windowMinutes())),
        ]);

        return $this->apiSuccess([
            'shift' => [
                'id' => $shift->id,
                'reference' => $shift->reference,
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
        \Log::info('Mobile Shift End Request', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
            'ip'      => $request->ip(),
        ]);

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

        $this->logShiftEvent($shift, 'SHIFT_ENDED', [
            'actual_end' => optional($shift->actual_end)->toISOString(),
        ]);

        return $this->apiSuccess([
            'shift' => [
                'id' => $shift->id,
                'reference' => $shift->reference,
                'status' => $shift->status,
                'actual_start' => optional($shift->actual_start)->toISOString(),
                'actual_end' => optional($shift->actual_end)->toISOString(),
                'duration_hours' => $shift->actual_duration !== null ? round($shift->actual_duration, 2) : null,
            ],
        ]);
    }

    /**
     * Append an immutable shift_events audit row. Best-effort — a logging
     * failure must never block the lifecycle action, so swallow and move on.
     */
    private function logShiftEvent(Shift $shift, string $eventType, array $metadata = []): void
    {
        try {
            ShiftEvent::create([
                'id' => (string) Str::uuid(),
                'shift_id' => $shift->id,
                'guard_id' => $shift->guard_id,
                'event_type' => $eventType,
                'metadata' => $metadata,
                'recorded_at' => Carbon::now(),
            ]);
        } catch (\Throwable $e) {
            // Audit is non-critical to the request; do not surface.
        }
    }

    /**
     * Shape a shift for GET /shifts/current (§5.5). can_start/can_end are
     * computed server-side from the window + status.
     */
    private function shiftPayload(Shift $shift): array
    {
        return [
            'id' => $shift->id,
            'reference' => $shift->reference,
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
