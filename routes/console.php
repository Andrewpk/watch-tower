<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled maintenance
|--------------------------------------------------------------------------
|
| Retention is only enforced while the scheduler runs. The Docker image runs
| `schedule:work` itself (RUN_SCHEDULER); elsewhere run `php artisan
| schedule:work` or a cron entry for `schedule:run`. `onOneServer()` relies on
| atomic cache locks, which the default `database` cache store provides, so
| replicas that share a database run each task once. The 720 minute mutex on
| `watch:prune` keeps a killed prune from blocking the next day's run.
|
*/

Schedule::command('watch:prune')->daily()->withoutOverlapping(720)->onOneServer();

Schedule::command('telescope:prune --hours=48')->daily()->withoutOverlapping()->onOneServer();

Schedule::command('queue:prune-failed --hours=168')->daily()->withoutOverlapping()->onOneServer();
