<?php

use App\Http\Middleware\AuthenticateIngestRequest;
use App\Http\Middleware\AuthenticateNightwatchIngest;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
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
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // Ingest throttling is keyed on the project resolved by these auth middleware.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateIngestRequest::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateNightwatchIngest::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
