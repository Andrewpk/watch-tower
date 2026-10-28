<?php

namespace App\Watch\Stats;

use Carbon\CarbonImmutable;

final class TimeRange
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
    ) {}

    /**
     * Build a TimeRange from a short label like "15m", "1h", "24h", "7d", "14d", "30d".
     * Falls back to 1h if the label isn't recognised.
     */
    public static function fromLabel(string $label): self
    {
        $label = strtolower($label);
        $now = CarbonImmutable::now();

        $map = [
            '15m' => $now->subMinutes(15),
            '1h' => $now->subHour(),
            '24h' => $now->subDay(),
            '7d' => $now->subDays(7),
            '14d' => $now->subDays(14),
            '30d' => $now->subDays(30),
        ];

        $from = $map[$label] ?? $map['1h'];

        return new self($from, $now, in_array($label, array_keys($map), true) ? $label : '1h');
    }

    public function humanLabel(): string
    {
        return match ($this->label) {
            '15m' => 'the last 15 minutes',
            '1h' => 'the last hour',
            '24h' => 'the last 24 hours',
            '7d' => 'the last 7 days',
            '14d' => 'the last 14 days',
            '30d' => 'the last 30 days',
            default => 'the selected window',
        };
    }
}
