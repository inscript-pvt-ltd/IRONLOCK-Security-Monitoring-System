<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register custom middleware aliases
        $middleware->alias([
            'admin.auth' => \App\Http\Middleware\AdminAuth::class,
            'guard.auth' => \App\Http\Middleware\GuardAuth::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Any exception that escapes a Mobile controller is rendered through
        // the same envelope/code mapping the controllers use, so the Flutter
        // client always receives the §3.2 error shape (never raw HTML or a
        // leaked stack trace). Scoped to the mobile API surface only.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/mobile/*')) {
                return \App\Support\Mobile\MobileResponse::fromException($e);
            }

            return null;
        });
    })->create();
