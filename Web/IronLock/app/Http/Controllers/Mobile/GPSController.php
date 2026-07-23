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

        // Snapshot the guard's state BEFORE applying the batch — finalizeFlush()
        // judges the net zone transition and the comms-gap window against this
        // (server-side facts only; the client clock is never trusted to decide
        // whether a backlog is a reconnect or whether to raise a live alert).
        $pre = $this->gpsService->flushPreState($guard->id);

        // Route the request: a LIVE tick (this shift's row, seen within the
        // backfill threshold) is debounced ping-by-ping and dispatches its own
        // grace job on a CONFIRMED exit; a RECONNECT (stale/absent/cross-shift
        // last-seen — the same signal finalizeFlush uses for its comms gap) is
        // replayed immediately and its single live decision is made in
        // finalizeFlush. The freshness test uses server-side last-seen only.
        //
        // `backfill:true` is an explicit producer signal that this POST is a
        // buffered reconnect drain, not a live 15s tick. When set it FORCES the
        // reconnect/replay path regardless of last-seen — the fix for a backlog
        // bigger than one batch (§6.1): chunk #1 refreshes the live row, so
        // without this flag chunk #2 would be misread as a live tick and
        // debounce/dispatch on HISTORICAL pings. Absent the flag we fall back to
        // the last-seen heuristic, so older apps are unaffected. It can only make
        // a batch MORE conservative (never turns a real live tick into paging),
        // so a mislabelled request degrades safely (the grace job re-confirms
        // against the live row before it ever raises).
        $isBackfill = $request->boolean('backfill');
        $threshold = (int) config('ironlock.gps_backfill_threshold_seconds', 60);
        $lastSeen = $pre['last_seen_at'];
        $isLiveTick = !$isBackfill
            && ($pre['shift_id'] === $shift->id)
            && $lastSeen instanceof \Carbon\Carbon
            && $lastSeen->diffInSeconds(now()) <= $threshold;

        $results = [];
        $pingsApplied = 0;

        // Process in order — the UPSERT means the last valid ping is the final
        // live position, and the transition logic sees each step in sequence.
        // On the LIVE path each ping is debounced ($dispatchZoneCheck = true) and
        // a confirmed exit dispatches inline; on the RECONNECT path dispatch is
        // suppressed per-ping so a replayed backlog never pages retroactively —
        // the single present-state decision is made once below in finalizeFlush().
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
            ], dispatchZoneCheck: $isLiveTick);

            $pingsApplied++;

            $results[] = [
                'recorded_at' => $ping['recorded_at'] ?? now()->toISOString(),
                'zone_status' => $location->zone_status,
                'requires_alert' => $location->zone_status === GeofenceService::STATUS_OUTSIDE_ZONE,
            ];
        }

        // Close out the flush once: present-state zone-exit dispatch (only on the
        // reconnect path — a live tick already dispatched inline on a confirmed
        // exit, so we suppress it here to avoid a second, mis-classified job) plus
        // the comms-gap / SYNC_FLUSH audit, which runs on either path.
        if ($pingsApplied > 0) {
            $this->gpsService->finalizeFlush(
                $guard->id,
                $shift->id,
                $pre,
                $pingsApplied,
                dispatchPresentState: !$isLiveTick
            );
        }

        return $this->apiSuccess(['results' => $results]);
    }
}
