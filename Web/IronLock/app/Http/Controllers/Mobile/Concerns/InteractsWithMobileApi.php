<?php

namespace App\Http\Controllers\Mobile\Concerns;

use App\Domains\Guards\Models\Guard;
use App\Support\Mobile\MobileResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * InteractsWithMobileApi — shared helpers for every Mobile\* controller.
 *
 * Thin wrapper over App\Support\Mobile\MobileResponse so controllers read
 * cleanly (`$this->apiSuccess(...)`) while the actual envelope shapes stay
 * defined in exactly one place. Also exposes the guard that GuardAuth attached
 * to the request.
 */
trait InteractsWithMobileApi
{
    /**
     * Success envelope (§3.2). Pass the endpoint-specific payload array.
     */
    protected function apiSuccess(mixed $data = null, int $status = 200): JsonResponse
    {
        return MobileResponse::success($data, $status);
    }

    /**
     * Error envelope (§3.2). `$details` is surfaced only for the cases the
     * contract documents (VALIDATION_ERROR field maps, login-window hint).
     */
    protected function apiError(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        return MobileResponse::error($code, $message, $status, $details);
    }

    /**
     * Standard 501 response for reserved Phase 3.3+ endpoints (§6).
     */
    protected function apiNotImplemented(): JsonResponse
    {
        return MobileResponse::error('NOT_IMPLEMENTED', 'This feature is not available yet.', 501);
    }

    /**
     * The authenticated guard, attached by the GuardAuth middleware.
     *
     * Only call this on routes behind `guard.auth`; it is null otherwise.
     */
    protected function currentGuard(Request $request): ?Guard
    {
        return $request->attributes->get('guard');
    }
}
