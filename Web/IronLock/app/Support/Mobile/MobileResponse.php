<?php

namespace App\Support\Mobile;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * MobileResponse — single source of truth for the mobile API envelope.
 *
 * Every `mobile/v1` response (success or error) is built here so the shapes
 * stay identical to the published contract in
 * Details/Important/MOBILE_API_INTEGRATION.md §3.2/§3.3/§8. Both the Mobile
 * controllers (via the InteractsWithMobileApi trait) and the global exception
 * handler (bootstrap/app.php) route through this class, so a payload looks the
 * same whether a controller returned it deliberately or an exception bubbled up.
 *
 *   Success: { "success": true,  "data": {...}, "meta": { "timestamp": ... } }
 *   Error:   { "success": false, "error": { "code", "message", "details?" } }
 */
class MobileResponse
{
    /**
     * Map an HTTP status to its canonical machine error code (§3.3/§8).
     */
    private const STATUS_CODE_MAP = [
        400 => 'BAD_REQUEST',
        401 => 'UNAUTHENTICATED',
        403 => 'FORBIDDEN',
        404 => 'NOT_FOUND',
        405 => 'BAD_REQUEST',
        409 => 'CONFLICT',
        422 => 'VALIDATION_ERROR',
        429 => 'RATE_LIMITED',
        501 => 'NOT_IMPLEMENTED',
    ];

    /**
     * Build a success envelope.
     */
    public static function success(mixed $data = null, int $status = 200): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $data,
            'meta' => [
                'timestamp' => now()->toISOString(),
            ],
        ], $status);
    }

    /**
     * Build an error envelope. `details` is included only when provided
     * (used for VALIDATION_ERROR field maps and the login-window hint).
     */
    public static function error(string $code, string $message, int $status, ?array $details = null): JsonResponse
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== null) {
            $error['details'] = $details;
        }

        return response()->json([
            'success' => false,
            'error' => $error,
        ], $status);
    }

    /**
     * Translate any throwable into the standard error envelope.
     *
     * Known framework exceptions map to their documented code/status. Anything
     * unrecognised is logged in full and returned as a generic SERVER_ERROR so
     * internal detail never leaks to the client (contract §3.2/§8).
     */
    public static function fromException(\Throwable $e): JsonResponse
    {
        if ($e instanceof ValidationException) {
            return self::error('VALIDATION_ERROR', 'The given data was invalid.', 422, $e->errors());
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return self::error('NOT_FOUND', 'The requested resource was not found.', 404);
        }

        if ($e instanceof AuthorizationException) {
            return self::error('FORBIDDEN', 'You are not allowed to perform this action.', 403);
        }

        if ($e instanceof ThrottleRequestsException) {
            return self::error('RATE_LIMITED', 'Too many requests. Please slow down and try again.', 429);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            $code = self::STATUS_CODE_MAP[$status] ?? 'SERVER_ERROR';

            // A bare HttpException (e.g. abort(403)) often has no safe message;
            // fall back to a generic one rather than exposing internals.
            $message = $e->getMessage() !== '' ? $e->getMessage() : self::defaultMessageFor($status);

            return self::error($code, $message, $status >= 400 && $status < 600 ? $status : 500);
        }

        // Unknown / unexpected — log the detail, return a generic 500.
        Log::error('Mobile API unhandled exception', [
            'exception' => $e::class,
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        return self::error('SERVER_ERROR', 'Something went wrong. Please try again later.', 500);
    }

    /**
     * Generic human-readable fallback message for a status code.
     */
    private static function defaultMessageFor(int $status): string
    {
        return match ($status) {
            400 => 'The request was malformed.',
            401 => 'Authentication is required.',
            403 => 'You are not allowed to perform this action.',
            404 => 'The requested resource was not found.',
            409 => 'The request conflicts with the current state.',
            429 => 'Too many requests. Please slow down and try again.',
            501 => 'This feature is not available yet.',
            default => 'Something went wrong. Please try again later.',
        };
    }
}
