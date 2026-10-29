<?php

use App\Http\Middleware\AuthenticateIngestRequest;
use App\Http\Middleware\AuthenticateNightwatchIngest;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\TrustProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Middleware\TrustProxies as FrameworkTrustProxies;
use Illuminate\Routing\Middleware\ThrottleRequests;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Reads config('app.trusted_proxies') per request: .env is not loaded yet when this callback runs.
        $middleware->replace(FrameworkTrustProxies::class, TrustProxies::class);

        // Capped at 10: the 6 fonts, the CSS, the app entry and the page chunk. An uncapped Link header grows
        // to ~3 KB on heavy pages and, with the cookies, overflows nginx's default 4 KB proxy_buffer_size (502).
        // The HTML still has a preload tag for every chunk, so only the early hints for the rest are lost.
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::using(10),
        ]);

        // Ingest throttling is keyed on the project resolved by these auth middleware.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateIngestRequest::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateNightwatchIngest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
