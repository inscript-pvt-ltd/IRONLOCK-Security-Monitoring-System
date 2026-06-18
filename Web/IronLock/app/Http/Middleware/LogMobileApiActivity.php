<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * TEMP — debug middleware for mobile API request/response inspection.
 * Remove by deleting this file and removing ->middleware(LogMobileApiActivity::class)
 * from the route group in routes/api.php.
 */
class LogMobileApiActivity
{
    public function handle(Request $request, Closure $next): Response
    {

        $response = $next($request);

        \Log::info('Mobile API Response', [
            'method'  => $request->method(),
            'url'     => $request->fullUrl(),
            'status'  => $response->getStatusCode(),
            'body'    => json_decode($response->getContent(), true),
        ]);

        return $response;
    }
}
