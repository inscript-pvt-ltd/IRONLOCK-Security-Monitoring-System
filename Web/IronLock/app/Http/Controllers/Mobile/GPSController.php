<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Geofences\Services\GeofenceService;
use App\Domains\GPS\Services\GPSTrackingService;
use App\Domains\Shifts\Models\Shift;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile GPS ingestion.
 *
 * Contract: Details/Important/MOBILE_API_INTEGRATION.md §6.1.
 * POST /shifts/{id}/locations — accepts a batch of pings so an app that briefly
 * buffered locations can flush them in one call (steady state is one ping every
 * 15s). The endpoint is behind GuardAuth; the shift must belong to the
 * authenticated guard and be active. Each ping is geofence-checked server-side
 * and UPSERTs the guard's single live-location row.
 */
class GPSController extends Controller
{
    use InteractsWithMobileApi;

    public function __construct(private readonly GPSTrackingService $gpsService) {}

    public function ping(Request $request, string $id): JsonResponse
    {
        $guard = $this->currentGuard($request);

        $pings = $request->input('pings');
        if (!is_array($pings) || count($pings) === 0) {
            return $this->apiError('VALIDATION_ERROR', 'pings must be a non-empty array.', 422);
        }

        // GPS is only meaningful for an active shift owned by this guard.
        $shift = Shift::where('id', $id)
            ->where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_ACTIVE)
            ->first();

        if (!$shift) {
            return $this->apiError('SHIFT_NOT_ACTIVE', 'No active shift found with this ID.', 409);
        }

        $results = [];

        // Process in order — the UPSERT means the last valid ping is the final
        // live position, and the transition logic sees each step in sequence.
        foreach ($pings as $ping) {
            $lat = $ping['latitude'] ?? null;
            $lng = $ping['longitude'] ?? null;

            if (!is_numeric($lat) || !is_numeric($lng)) {
                $results[] = [
                    'recorded_at' => $ping['recorded_at'] ?? now()->toISOString(),
                    'zone_status' => null,
                    'requires_alert' => false,
                    'error' => 'Invalid coordinates',
                ];
                continue;
            }

            $location = $this->gpsService->recordLocation($guard->id, $shift->id, [
                'latitude' => (float) $lat,
                'longitude' => (float) $lng,
                'accuracy' => isset($ping['accuracy']) ? (float) $ping['accuracy'] : null,
                // battery arrives as a 0.0–1.0 fraction; store as integer percent.
                'battery_level' => isset($ping['battery'])
                    ? (int) round((float) $ping['battery'] * 100)
                    : null,
                'recorded_at' => $ping['recorded_at'] ?? null,
            ]);

            $results[] = [
                'recorded_at' => $ping['recorded_at'] ?? now()->toISOString(),
                'zone_status' => $location->zone_status,
                'requires_alert' => $location->zone_status === GeofenceService::STATUS_OUTSIDE_ZONE,
            ];
        }

        return $this->apiSuccess(['results' => $results]);
    }
}
