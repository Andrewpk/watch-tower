<?php

namespace Tests;

use App\Watch\Databases;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * The app, telemetry and log databases are separate connections, so each needs its own migrated database and
     * its own transaction. `null` is the default (app) connection.
     *
     * @var list<string|null>
     */
    protected $connectionsToTransact = [null, Databases::TELEMETRY, Databases::LOGS];
}
