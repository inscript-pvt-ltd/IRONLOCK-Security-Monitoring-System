<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Shifts\Models\Shift;
use App\Domains\Wakefulness\Models\WakefulnessCheck;
use App\Domains\Wakefulness\Services\WakefulnessService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Mobile wakefulness verification.
 *
 * Contract: Details/Important/MOBILE_API_INTEGRATION.md §6.2.
 *   POST /wakefulness/{checkId}/respond  — answer a code-challenge.
 *   POST /wakefulness/{checkId}/received — confirm the online push arrived
 *                                          (Phase 6 push reliability).
 *
 * Frozen response shape: { "data": { "result": "PASSED" | "FAILED" } }. A
 * FAILED result is a normal 200 outcome (the guard answered, but wrong/late) —
 * not an error. Only a genuinely unknown check id is a 404. All pass/fail
 * authority lives in WakefulnessService; this controller is transport only.
 *
 * Offline extension (additive, contract-compatible): an app replaying an
 * offline TOTP response may include `window_reference` (the TOTP time-step it
 * used) and `is_offline:true`; the server re-derives the code from the shift
 * seed for that window instead of comparing to a stored online code.
 */
class WakefulnessController extends Controller
{
    use InteractsWithMobileApi;

    public function __construct(private readonly WakefulnessService $wakefulness) {}

    /**
     * Outstanding (PENDING) ONLINE wakefulness challenges for the guard's active
     * shift — the push-fallback discovery path (Phase 6/7), mirroring the photo
     * `pending` poll. An app that missed the FCM push, or is already foregrounded
     * when a challenge fires, polls this to find the challenge and raise the
     * code-entry sheet in-app instead of depending on a notification tap.
     *
     * Only ONLINE checks appear: the server pushes their code (returned here so
     * the app can show it), and only they have a live response deadline. Offline
     * TOTP challenges are computed on-device and never live as server rows.
     */
    public function pending(Request $request, string $shiftId): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $shift = Shift::where('id', $shiftId)
            ->where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_ACTIVE)
            ->first();
        if (!$shift) {
            return $this->apiError('SHIFT_NOT_ACTIVE', 'No active shift found with this ID.', 409);
        }

        $responseSeconds = (int) config('ironlock.wakefulness_response_seconds', 60);

        $challenges = WakefulnessCheck::where('shift_id', $shift->id)
            ->whereNull('result') // PENDING
            ->where('online_or_offline', WakefulnessCheck::MODE_ONLINE)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (WakefulnessCheck $c) => [
                'check_id' => $c->id,
                'shift_id' => $c->shift_id,
                // The code the guard must transcribe (server-pushed; echoed here
                // so a poll-discovered challenge shows the same code as the push).
                'code' => $c->challenge_code,
                'request_type' => $c->request_type, // manual | scheduled
                // Countdown anchor + hard deadline, server-anchored (mirror photos).
                'scheduled_at' => optional($c->scheduled_at)->toISOString(),
                'issued_at' => optional($c->scheduled_at)->toISOString(),
                'response_seconds' => $responseSeconds,
                'expires_at' => $c->scheduled_at
                    ? $c->scheduled_at->copy()->addSeconds($responseSeconds)->toISOString()
                    : null,
            ])->values();

        return $this->apiSuccess(['challenges' => $challenges]);
    }

    public function respond(Request $request, string $checkId): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $validator = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:8'],
            'responded_at' => ['sometimes', 'nullable', 'string'],
            'window_reference' => ['sometimes', 'nullable', 'integer'],
            'is_offline' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        $result = $this->wakefulness->respond(
            $guard,
            $checkId,
            (string) $request->input('code'),
            $request->input('responded_at'),
            $request->filled('window_reference') ? (int) $request->input('window_reference') : null
        );

        if ($result['reason'] === 'CHECK_NOT_FOUND') {
            return $this->apiError('NOT_FOUND', 'Wakefulness check not found.', 404);
        }

        return $this->apiSuccess(['result' => $result['result']]);
    }

    /**
     * Flush an OFFLINE wakefulness result on reconnect (spec §9.4).
     *
     * While the guard was offline no server check row was created (the dispatcher
     * skips unreachable devices), so there is no checkId to POST to respond().
     * The app instead flushes {window_reference, code, scheduled_at?, responded_at?}
     * here and the server MATERIALISES the check from it — the offline analogue of
     * the offline-photo upload, which likewise creates its record on arrival.
     * Pass/fail is decided by re-deriving the TOTP for the window; a wrong code is
     * recorded FAILED for audit but never raises a retroactive alert. Idempotent
     * per (shift, window): a re-flush echoes the first outcome.
     */
    public function offline(Request $request, string $shiftId): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $validator = Validator::make($request->all(), [
            'window_reference' => ['required', 'integer'],
            'code' => ['required', 'string', 'max:8'],
            'scheduled_at' => ['sometimes', 'nullable', 'string'],
            'responded_at' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        // An offline replay legitimately arrives AFTER the shift may have ended
        // (the guard reconnects post-shift), so match the shift to the guard
        // without the active constraint — mirroring reviews(). The TOTP window +
        // shift seed are the real authority for what this result belongs to.
        $shift = Shift::where('id', $shiftId)->where('guard_id', $guard->id)->first();
        if (!$shift) {
            return $this->apiError('SHIFT_NOT_FOUND', 'No shift found with this ID.', 404);
        }

        $result = $this->wakefulness->recordOfflineResult(
            $guard,
            $shift,
            (int) $request->input('window_reference'),
            (string) $request->input('code'),
            $request->input('scheduled_at'),
            $request->input('responded_at'),
        );

        if ($result['reason'] === 'SEED_UNAVAILABLE') {
            return $this->apiError('SEED_UNAVAILABLE', 'This shift has no wakefulness seed provisioned.', 409);
        }

        return $this->apiSuccess([
            'result' => $result['result'],
            'reason' => $result['reason'],
        ]);
    }

    /**
     * Confirm an ONLINE challenge push was received (Phase 6 push reliability).
     * The app calls this fire-and-forget the instant the challenge notification
     * lands, so the timeout sweep can distinguish "ignored" from "never
     * delivered". Idempotent; only an unknown check id is a 404.
     */
    public function received(Request $request, string $checkId): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $result = $this->wakefulness->acknowledgeDelivery($guard, $checkId);

        if ($result['reason'] === 'CHECK_NOT_FOUND') {
            return $this->apiError('NOT_FOUND', 'Wakefulness check not found.', 404);
        }

        return $this->apiSuccess(['acknowledged' => true]);
    }
}
