<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * The scheduled event whose command contains the given text.
 */
function scheduledEventFor(string $command): ?Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains($event->command ?? '', $command));
}

beforeEach(function () {
    $this->artisan('schedule:list')->assertSuccessful();
});

it('schedules the daily retention prune without overlap and on one server', function () {
    $event = scheduledEventFor('watch:prune');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(720)
        ->and($event->onOneServer)->toBeTrue();
});

it('schedules the daily telescope prune for 48 hours', function () {
    $event = scheduledEventFor('telescope:prune --hours=48');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('schedules the daily failed job prune for 168 hours', function () {
    $event = scheduledEventFor('queue:prune-failed --hours=168');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->onOneServer)->toBeTrue();
});

it('lists the three commands in schedule:list', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('watch:prune')
        ->expectsOutputToContain('telescope:prune --hours=48')
        ->expectsOutputToContain('queue:prune-failed --hours=168')
        ->assertSuccessful();
});
