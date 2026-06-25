<?php

namespace App\Domains\Notifications\Services;

use App\Domains\Guards\Models\Guard;
use App\Domains\Photos\Models\PhotoRequest;
use App\Domains\Photos\Models\PhotoReview;
use Illuminate\Support\Facades\Log;

/**
 * PhotoPushNotifier — best-effort delivery of a photo request to the guard's
 * device.
 *
 * FCM is formally Phase 6 and FcmService is still a stub whose constructor
 * reads a Firebase credentials file that does not exist in dev. So this is
 * deliberately defensive: it resolves FcmService lazily and swallows ANY
 * failure. Delivery is a convenience — the photo_request already exists and is
 * visible to admins, and the app can poll `photos/pending` — so a push failure
 * must never break request creation.
 */
class PhotoPushNotifier
{
    public function notify(Guard $guard, PhotoRequest $request): bool
    {
        if (empty($guard->push_token)) {
            return false;
        }

        try {
            /** @var FcmService $fcm */
            $fcm = app(FcmService::class);

            return $fcm->sendToDevice(
                $guard->push_token,
                'Photo required',
                'Open the app now to capture a live verification photo.',
                [
                    'type' => 'PHOTO_REQUEST',
                    'request_id' => $request->id,
                    'shift_id' => $request->shift_id,
                    'nonce_value' => optional($request->nonce)->nonce_value ?? '',
                    // Timing anchor so the app shows an exact server-side
                    // countdown (and survives a force-stopped app). issued_at is
                    // the nonce-issue instant the expiry is measured from;
                    // response_seconds is the window length the server enforces.
                    // (FcmService stringifies all data values.)
                    'issued_at' => optional($request->nonce_issued_at)->toISOString() ?? '',
                    'response_seconds' => (int) config('ironlock.photo_response_seconds', 90),
                ]
            );
        } catch (\Throwable $e) {
            // FCM not configured / not yet implemented (Phase 6) — no-op.
            Log::info('PhotoPushNotifier: push skipped', [
                'request_id' => $request->id,
                'reason' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Best-effort: tell the guard's app an admin reviewed one of their photos
     * (APPROVED / REJECTED). The mobile reviews poll is the reliable source of
     * truth — this push is only an instant nudge, so any failure is swallowed.
     */
    public function notifyReviewed(Guard $guard, PhotoReview $review): bool
    {
        if (empty($guard->push_token)) {
            return false;
        }

        $approved = $review->decision === PhotoReview::DECISION_APPROVED;

        try {
            /** @var FcmService $fcm */
            $fcm = app(FcmService::class);

            return $fcm->sendToDevice(
                $guard->push_token,
                $approved ? 'Photo approved' : 'Photo rejected',
                $approved
                    ? 'Your verification photo was approved.'
                    : 'Your verification photo was rejected. Tap for details.',
                [
                    'type' => 'PHOTO_REVIEWED',
                    'request_id' => $review->photo_request_id,
                    'shift_id' => $review->shift_id,
                    'decision' => $review->decision,
                    'note' => (string) ($review->note ?? ''),
                ]
            );
        } catch (\Throwable $e) {
            Log::info('PhotoPushNotifier: review push skipped', [
                'review_id' => $review->id,
                'reason' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
