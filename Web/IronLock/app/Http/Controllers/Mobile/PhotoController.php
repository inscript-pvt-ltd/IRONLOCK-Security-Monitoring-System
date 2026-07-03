<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Photos\Models\PhotoReview;
use App\Domains\Photos\Services\PhotoVerificationService;
use App\Domains\Shifts\Models\Shift;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Mobile photo verification.
 *
 * Contract: Details/Important/MOBILE_API_INTEGRATION.md §6.3.
 *  - GET  /shifts/{id}/photos/pending — outstanding requests (push fallback).
 *  - POST /shifts/{id}/photos         — upload a signed live photo.
 *
 * All validation/liveness logic lives in PhotoVerificationService; this
 * controller handles transport, file constraints and the §3.2 envelope.
 */
class PhotoController extends Controller
{
    use InteractsWithMobileApi;

    public function __construct(private readonly PhotoVerificationService $photos) {}

    /**
     * Outstanding (PENDING) photo requests for the guard's active shift, so an
     * app that missed/has no push can discover what it must respond to.
     */
    public function pending(Request $request, string $id): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $shift = $this->activeShift($id, $guard->id);
        if (!$shift) {
            return $this->apiError('SHIFT_NOT_ACTIVE', 'No active shift found with this ID.', 409);
        }

        $requests = PhotoRequest::with('nonce')
            ->where('shift_id', $shift->id)
            ->where('status', PhotoRequest::STATUS_PENDING)
            ->orderBy('requested_at')
            ->get()
            ->map(fn (PhotoRequest $r) => [
                'request_id' => $r->id,
                'nonce_value' => optional($r->nonce)->nonce_value,
                'nonce_issued_at' => optional($r->nonce_issued_at)->toISOString(),
                'nonce_expires_at' => optional(optional($r->nonce)->expires_at)->toISOString(),
                'requested_at' => optional($r->requested_at)->toISOString(),
                'request_type' => $r->request_type,
                // Server-anchored countdown fields (mirror the push data). issued_at
                // is the instant the expiry is measured from; expires_at is the hard
                // deadline; response_seconds is the window length the server enforces.
                'issued_at' => optional($r->nonce_issued_at)->toISOString(),
                'expires_at' => optional(optional($r->nonce)->expires_at)->toISOString(),
                'response_seconds' => (int) config('ironlock.photo_response_seconds', 90),
            ])->values();

        return $this->apiSuccess(['requests' => $requests]);
    }

    /**
     * Upload a signed live photo (multipart/form-data).
     */
    public function upload(Request $request, string $id): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $shift = $this->activeShift($id, $guard->id);
        if (!$shift) {
            return $this->apiError('SHIFT_NOT_ACTIVE', 'No active shift found with this ID.', 409);
        }

        // Two accepted shapes, both validated the same way downstream:
        //   - legacy single: `photo` (file) + `signature` (string)
        //   - multi (1–5):   `photos[]` (files) + `signatures[]` (parallel)
        // Each image is signed independently (HMAC over its own bytes), so a
        // multi upload carries one signature per image.
        $isMulti = $request->hasFile('photos') || is_array($request->input('signatures'));
        $max = PhotoVerificationService::MAX_PHOTOS_PER_REQUEST;

        $rules = [
            'nonce_value' => ['required', 'string'],
            'request_id' => ['sometimes', 'nullable', 'string'],
            'latitude' => ['sometimes', 'nullable', 'numeric'],
            'longitude' => ['sometimes', 'nullable', 'numeric'],
            'captured_at' => ['sometimes', 'nullable', 'string'],
            'exif_timestamp' => ['sometimes', 'nullable', 'string'],
            'ntp_timestamp' => ['sometimes', 'nullable', 'string'],
            'ntp_reference' => ['sometimes', 'nullable', 'string'],
            'elapsed_seconds' => ['sometimes', 'nullable', 'numeric'],
        ];

        if ($isMulti) {
            $rules['photos'] = ['required', 'array', 'min:1', "max:{$max}"];
            $rules['photos.*'] = ['required', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:10240']; // 10 MB each
            $rules['signatures'] = ['required', 'array', 'min:1', "max:{$max}"];
            $rules['signatures.*'] = ['required', 'string'];
        } else {
            $rules['photo'] = ['required', 'file', 'image', 'mimes:jpeg,jpg,png', 'max:10240']; // 10 MB
            $rules['signature'] = ['required', 'string'];
        }

        $validator = Validator::make($request->all(), $rules);

        // One signature per image, positionally matched.
        $validator->after(function ($v) use ($request, $isMulti) {
            if ($isMulti && count((array) $request->file('photos')) !== count((array) $request->input('signatures'))) {
                $v->errors()->add('signatures', 'Each photo must have exactly one matching signature.');
            }
        });

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        // Normalise to a list of {file, signature} items the service stores as
        // individual evidence rows under the one request.
        if ($isMulti) {
            $files = array_values($request->file('photos'));
            $sigs = array_values((array) $request->input('signatures'));
            $items = [];
            foreach ($files as $i => $file) {
                $items[] = ['file' => $file, 'signature' => (string) ($sigs[$i] ?? '')];
            }
        } else {
            $items = [['file' => $request->file('photo'), 'signature' => (string) $request->input('signature')]];
        }

        $result = $this->photos->submitPhotos(
            $guard,
            $shift,
            $items,
            $request->only([
                'request_id', 'nonce_value', 'latitude', 'longitude',
                'captured_at', 'exif_timestamp', 'ntp_timestamp', 'ntp_reference', 'elapsed_seconds',
            ])
        );

        // A hard rejection (replay, expired/used nonce, bad HMAC, timeline
        // anomaly) is a 422 the app must surface; flagged photos are accepted.
        if ($result['result'] === 'REJECTED') {
            return $this->apiError('PHOTO_REJECTED', 'Photo verification failed: ' . $result['reason'], 422, [
                'reason' => $result['reason'],
            ]);
        }

        return $this->apiSuccess([
            'result' => $result['result'],
            'flags' => $result['flags'],
            // How many images were stored for this request (1–5). Legacy single
            // uploads return 1, so existing clients are unaffected.
            'count' => $result['count'] ?? 1,
        ]);
    }

    /**
     * Admin review outcomes for this shift's photos (Phase 4 follow-up).
     *
     * The guard's app polls this to learn whether a supervisor approved or
     * rejected a submitted photo (it is also pushed best-effort via FCM
     * `PHOTO_REVIEWED`). Returns one entry per reviewed photo, newest first.
     */
    public function reviews(Request $request, string $id): JsonResponse
    {
        $guard = $this->currentGuard($request);

        // Unlike pending/upload, reviews remain useful after a shift ends (an
        // admin may review later), so match the shift to the guard without the
        // active-status constraint.
        $shift = Shift::where('id', $id)->where('guard_id', $guard->id)->first();
        if (!$shift) {
            return $this->apiError('SHIFT_NOT_FOUND', 'No shift found with this ID.', 404);
        }

        $reviews = PhotoReview::where('shift_id', $shift->id)
            ->where('guard_id', $guard->id)
            ->orderByDesc('reviewed_at')
            ->get()
            ->map(fn (PhotoReview $r) => [
                'request_id' => $r->photo_request_id,
                'decision' => $r->decision,            // APPROVED | REJECTED
                'note' => $r->note,
                'reviewed_at' => optional($r->reviewed_at)->toISOString(),
            ])->values();

        return $this->apiSuccess(['reviews' => $reviews]);
    }

    private function activeShift(string $id, string $guardId): ?Shift
    {
        return Shift::where('id', $id)
            ->where('guard_id', $guardId)
            ->where('status', Shift::STATUS_ACTIVE)
            ->first();
    }
}
