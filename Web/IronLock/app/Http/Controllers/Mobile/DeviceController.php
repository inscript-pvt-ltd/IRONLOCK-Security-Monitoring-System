<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Mobile device registration.
 *
 * Contract: Details/Important/MOBILE_API_INTEGRATION.md §6.5.
 * POST /devices/push-token — registers the FCM/APNs token used to deliver
 * photo requests (and, later, wakefulness challenges). Best-effort delivery
 * lands with FcmService in Phase 6; storing the token now lets that drop in
 * with no app change.
 */
class DeviceController extends Controller
{
    use InteractsWithMobileApi;

    public function registerPushToken(Request $request): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $validator = Validator::make($request->all(), [
            'push_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'in:ios,android'],
        ]);

        if ($validator->fails()) {
            return $this->apiError('VALIDATION_ERROR', 'The given data was invalid.', 422, $validator->errors()->toArray());
        }

        $guard->forceFill([
            'push_token' => (string) $request->input('push_token'),
            'push_token_platform' => (string) $request->input('platform'),
        ])->save();

        return $this->apiSuccess(['message' => 'Push token registered.']);
    }
}
