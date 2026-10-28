<?php

use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\NightwatchAgentAuthController;
use App\Http\Controllers\Api\NightwatchIngestController;
use App\Http\Middleware\AuthenticateIngestRequest;
use App\Http\Middleware\AuthenticateNightwatchAgent;
use App\Http\Middleware\AuthenticateNightwatchIngest;
use Illuminate\Support\Facades\Route;

// The ingest limiter is keyed on the project resolved by the auth middleware,
// so throttle:ingest must run after it (AppServiceProvider pins that order in
// the router's middleware priority list). agent-auth is keyed on the client IP
// and runs first so that guessed environment tokens are throttled too.

Route::middleware([AuthenticateIngestRequest::class, 'throttle:ingest'])
    ->prefix('ingest')
    ->group(function () {
        Route::post('/', [IngestController::class, 'batch'])->name('api.ingest.batch');
        Route::post('/sync', [IngestController::class, 'sync'])->name('api.ingest.sync');
    });

Route::post('/agent-auth', NightwatchAgentAuthController::class)
    ->middleware(['throttle:agent-auth', AuthenticateNightwatchAgent::class])
    ->name('api.nightwatch.agent-auth');

Route::post('/nightwatch-ingest', NightwatchIngestController::class)
    ->middleware([AuthenticateNightwatchIngest::class, 'throttle:ingest'])
    ->name('api.nightwatch.ingest');
