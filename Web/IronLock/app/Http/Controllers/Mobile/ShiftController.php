<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Shifts\Models\Shift;
use App\Domains\Shifts\Models\ShiftEvent;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
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
            // Wakefulness provisioning (Phase 5, contract §5.6). Delivered ONCE
            // at start, HTTPS-only: the decrypted offline TOTP seed + parameters
            // so the app can compute offline challenge codes, and the randomised
            // challenge schedule so it can fire matching offline local
            // notifications at the same times the server pushes online ones.
            'wakefulness' => [
                'totp_seed' => $shift->totp_seed,
                'totp_period_seconds' => (int) config('ironlock.totp_period_seconds', 30),
                'totp_digits' => (int) config('ironlock.totp_digits', 4),
                'response_seconds' => (int) config('ironlock.wakefulness_response_seconds', 60),
                'schedule' => $shift->wakefulness_schedule ?? [],
            ],
        ]);
    }

    /**
     * POST /shifts/{id}/end — end an active shift (BACKEND_SHIFT_END_SPEC §1).
     *
     * Two paths, both server-enforced:
     *  - Normal end (ended_early:false): allowed only once scheduled_end has
     *    passed. Before then the guard must go through the early-end request.
     *  - Early end (ended_early:true): allowed only when a supervisor has
     *    APPROVED the early-end request — the server never trusts the client to
     *    self-approve. The stored request reason/note are used as the record.
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

        if ($shift->status !== Shift::STATUS_ACTIVE) {
            return $this->apiError('SHIFT_NOT_ENDABLE', 'This shift is not active.', 409);
        }

        $endedEarly = $request->boolean('ended_early');
        $reason = $request->input('reason');
        $note = $request->input('note');

        if ($endedEarly) {
            // The server is the authority: refuse an early end without an
            // approved request so a tampered client cannot skip approval.
            if (!$shift->hasApprovedEarlyEnd()) {
                return $this->apiError(
                    'EARLY_END_NOT_APPROVED',
                    'Your early-end request has not been approved by a supervisor.',
                    409
                );
            }

            // Trust the reason/note recorded on the approved request; fall back
            // to the client-supplied values only to fill a gap. Never reject an
            // approved end over a missing reason — close it and flag for review.
            $reason = $shift->early_end_reason ?: $reason;
            $note = $shift->early_end_note ?: $note;
            $endType = Shift::END_TYPE_EARLY;
        } else {
            // A normal end is only honest once the contracted end has passed.
            if (!$shift->canEnd()) {
                return $this->apiError(
                    'END_BEFORE_SCHEDULED',
                    'You cannot end this shift before its scheduled end time. Request an early end if you need to leave.',
                    409
                );
            }

            $endType = Shift::END_TYPE_GUARD;
            $reason = null;
            $note = null;
        }

        $shift->end('mobile_app', $endType, $reason, $note);
        $shift->refresh();

        $this->logShiftEvent($shift, 'SHIFT_ENDED', [
            'actual_end' => optional($shift->actual_end)->toISOString(),
            'end_type' => $shift->end_type,
            'ended_early' => (bool) $shift->ended_early,
            'reason' => $reason,
            'note' => $note,
        ]);

        return $this->apiSuccess([
            'shift' => [
                'id' => $shift->id,
                'reference' => $shift->reference,
                'status' => $shift->status,
                'end_type' => $shift->end_type,
                'actual_start' => optional($shift->actual_start)->toISOString(),
                'actual_end' => optional($shift->actual_end)->toISOString(),
                'duration_hours' => $shift->actual_duration !== null ? round($shift->actual_duration, 2) : null,
            ],
        ]);
    }

    /**
     * POST /shifts/{id}/early-end-request — guard asks to leave before
     * scheduled_end (BACKEND_SHIFT_END_SPEC §0.1). Does NOT end the shift; it
     * records a pending request for a supervisor to approve/reject. The app
     * locks its END button and polls GET /shifts/current for the decision.
     */
    public function earlyEndRequest(Request $request, string $id): JsonResponse
    {
        \Log::info('Mobile Early End Request', [
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

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:100',
            'note' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'Please provide a reason for leaving early.', 422, $validator->errors()->toArray());
        }

        // Only valid for an active shift before its scheduled end; otherwise the
        // app falls back to a normal end.
        if (!$shift->canRequestEarlyEnd()) {
            return $this->apiError(
                'EARLY_END_NOT_APPLICABLE',
                'An early-end request can only be made during an active shift before its scheduled end.',
                409
            );
        }

        $shift->requestEarlyEnd($request->input('reason'), $request->input('note'));
        $shift->refresh();

        $this->logShiftEvent($shift, 'EARLY_END_REQUESTED', [
            'reason' => $shift->early_end_reason,
            'note' => $shift->early_end_note,
            'requested_at' => optional($shift->early_end_requested_at)->toISOString(),
        ]);

        // NOTE: supervisor notification (push/dashboard) is handled out of band.

        return $this->apiSuccess(['shift' => $this->shiftPayload($shift)]);
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
                // Authoritative server timestamp, assigned in PHP (UTC) at receipt.
                // Set explicitly so it never falls back to the DB CURRENT_TIMESTAMP
                // default, which uses the DB session timezone (not UTC).
                'server_received_at' => Carbon::now(),
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
            'can_request_early_end' => $shift->canRequestEarlyEnd(),
            'early_end_request' => $shift->earlyEndRequestPayload(),
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
