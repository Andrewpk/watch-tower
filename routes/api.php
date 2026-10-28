<?php

use App\Http\Controllers\Api\IngestController;
use App\Http\Controllers\Api\NightwatchAgentAuthController;
use App\Http\Controllers\Api\NightwatchIngestController;
use App\Http\Middleware\AuthenticateIngestRequest;
use App\Http\Middleware\AuthenticateNightwatchAgent;
use App\Http\Middleware\AuthenticateNightwatchIngest;
use Illuminate\Support\Facades\Route;

Route::middleware(AuthenticateIngestRequest::class)
    ->prefix('ingest')
    ->group(function () {
        Route::post('/', [IngestController::class, 'batch'])->name('api.ingest.batch');
        Route::post('/sync', [IngestController::class, 'sync'])->name('api.ingest.sync');
    });

Route::post('/agent-auth', NightwatchAgentAuthController::class)
    ->middleware(AuthenticateNightwatchAgent::class)
    ->name('api.nightwatch.agent-auth');

Route::post('/nightwatch-ingest', NightwatchIngestController::class)
    ->middleware(AuthenticateNightwatchIngest::class)
    ->name('api.nightwatch.ingest');
