<?php

use App\Watch\Stats\TimeRange;

it('recognises every range label offered by the front-end range tabs', function () {
    foreach (['15m', '1h', '24h', '7d', '14d', '30d'] as $label) {
        expect(TimeRange::fromLabel($label)->label)->toBe($label);
    }
});

it('builds a 15m window starting 15 minutes ago', function () {
    $range = TimeRange::fromLabel('15m');

    expect($range->label)->toBe('15m')
        ->and((int) $range->from->diffInMinutes($range->to))->toBe(15)
        ->and($range->humanLabel())->toBe('the last 15 minutes');
});

it('falls back to 1h for unrecognised labels', function () {
    expect(TimeRange::fromLabel('nonsense')->label)->toBe('1h');
});
