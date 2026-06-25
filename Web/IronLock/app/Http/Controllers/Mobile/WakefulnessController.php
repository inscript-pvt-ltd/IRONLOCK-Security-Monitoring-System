<?php

namespace App\Http\Controllers\Mobile;

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

    public function respond(Request $request, string $checkId): JsonResponse
    {
        \Log::info('Mobile Wakefulness Respond Request', [
            'check_id' => $checkId,
            'body' => $request->all(),
            'ip' => $request->ip(),
        ]);

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
     * Confirm an ONLINE challenge push was received (Phase 6 push reliability).
     * The app calls this fire-and-forget the instant the challenge notification
     * lands, so the timeout sweep can distinguish "ignored" from "never
     * delivered". Idempotent; only an unknown check id is a 404.
     */
    public function received(Request $request, string $checkId): JsonResponse
    {
        \Log::info('Mobile Wakefulness Received Request', [
            'check_id' => $checkId,
            'headers'  => $request->headers->all(),
            'body'     => $request->all(),
            'ip'       => $request->ip(),
        ]);

        $guard = $this->currentGuard($request);

        $result = $this->wakefulness->acknowledgeDelivery($guard, $checkId);

        if ($result['reason'] === 'CHECK_NOT_FOUND') {
            return $this->apiError('NOT_FOUND', 'Wakefulness check not found.', 404);
        }

        return $this->apiSuccess(['acknowledged' => true]);
    }
}
