<?php

namespace App\Models\Concerns;

/**
 * Keeps a model on the app database, whatever model it is reached from.
 *
 * Eloquent gives a related model that does not name a connection the connection of the model it came from, so
 * `$trace->project` would otherwise look for `projects` in the telemetry database. The default connection is read
 * when the model is used, never when the class loads, so tests and commands can change it.
 */
trait UsesAppDatabase
{
    public function getConnectionName(): ?string
    {
        return $this->connection ?? config('database.default');
    }
}
