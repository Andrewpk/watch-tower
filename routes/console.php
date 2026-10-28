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
| Retention is only enforced while the scheduler runs (`php artisan
| schedule:work`, or a cron entry for `schedule:run`). `onOneServer()` relies
| on atomic cache locks, which the default `database` cache store provides.
|
*/

Schedule::command('watch:prune')->daily()->withoutOverlapping()->onOneServer();

Schedule::command('telescope:prune --hours=48')->daily();

Schedule::command('queue:prune-failed --hours=168')->daily();
