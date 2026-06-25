<?php

namespace App\Http\Controllers\Mobile;

use App\Domains\Nonces\Models\Nonce;
use App\Domains\Nonces\Services\NonceService;
use App\Domains\Shifts\Models\Shift;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Mobile\Concerns\InteractsWithMobileApi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile nonce management — the offline liveness-proof pool.
 *
 * Contract: Details/Important/MOBILE_API_INTEGRATION.md §6.4.
 * POST /shifts/{id}/nonces/prefetch — the app calls this at shift start and
 * whenever its local pool drops below ~5, while online. Each nonce is a
 * single-use, 15-minute liveness token the app draws from when capturing a
 * photo offline (spec §9.4.2).
 */
class NonceController extends Controller
{
    use InteractsWithMobileApi;

    public function __construct(private readonly NonceService $nonces) {}

    public function prefetch(Request $request, string $id): JsonResponse
    {
        \Log::info('Mobile Nonce Prefetch Request', [
            'shift_id' => $id,
            'headers'  => $request->headers->all(),
            'body'     => $request->all(),
            'ip'       => $request->ip(),
        ]);

        $guard = $this->currentGuard($request);

        $shift = Shift::where('id', $id)
            ->where('guard_id', $guard->id)
            ->where('status', Shift::STATUS_ACTIVE)
            ->first();

        if (!$shift) {
            return $this->apiError('SHIFT_NOT_ACTIVE', 'No active shift found with this ID.', 409);
        }

        $count = (int) $request->input('count', NonceService::DEFAULT_POOL_SIZE);

        $pool = $this->nonces->issuePool($guard, $shift, $count)->map(fn (Nonce $n) => [
            'nonce_value' => $n->nonce_value,
            'issued_at' => $n->issued_at->toISOString(),
            'expires_at' => $n->expires_at->toISOString(),
            'type' => $n->type,
        ])->values();

        return $this->apiSuccess([
            'nonces' => $pool,
            'expiry_minutes' => NonceService::OFFLINE_TTL_MINUTES,
            'remaining' => $this->nonces->remainingPoolCount($guard, $shift),
        ]);
    }
}
