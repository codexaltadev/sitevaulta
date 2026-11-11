<?php

use App\Http\Middleware\ServeStatusPageOnCustomDomain;
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
        // Before routing: status pages on their customers' own domains (status.client.com).
        $middleware->prepend(ServeStatusPageOnCustomDomain::class);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
            'api/*',
            'billing/checkout/*',
            'heartbeat/*',
            // Server-to-server, authenticated by an HMAC signature instead of a session.
            'probe-agent/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
