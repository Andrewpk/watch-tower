<?php

use App\Models\Project;
use App\Watch\Sampler;

/**
 * @return array{type: string, id: string, data: array<string, mixed>}
 */
function samplerRequestEvent(string $traceId): array
{
    return ['type' => 'request', 'id' => $traceId, 'data' => ['request_id' => $traceId]];
}

it('makes the same decision every time for the same id', function () {
    $project = Project::factory()->make(['sampling_rate' => 0.5]);
    $sampler = new Sampler;

    foreach (range(1, 200) as $i) {
        $event = samplerRequestEvent("trace-{$i}");
        $first = $sampler->shouldKeep($project, $event);

        expect($sampler->shouldKeep($project, $event))->toBe($first)
            ->and((new Sampler)->shouldKeep($project, $event))->toBe($first);
    }
});

it('keeps roughly the configured share of requests', function () {
    $project = Project::factory()->make(['sampling_rate' => 0.5]);
    $sampler = new Sampler;

    $kept = collect(range(1, 2000))
        ->filter(fn (int $i) => $sampler->shouldKeep($project, samplerRequestEvent("trace-{$i}")))
        ->count();

    expect($kept)->toBeGreaterThan(900)->toBeLessThan(1100);
});

it('keeps everything at rate 1', function () {
    $project = Project::factory()->make(['sampling_rate' => 1.0]);
    $sampler = new Sampler;

    foreach (range(1, 200) as $i) {
        expect($sampler->shouldKeep($project, samplerRequestEvent("trace-{$i}")))->toBeTrue();
    }
});

it('keeps nothing but exceptions at rate 0', function () {
    $project = Project::factory()->make(['sampling_rate' => 0.0]);
    $sampler = new Sampler;

    foreach (range(1, 200) as $i) {
        expect($sampler->shouldKeep($project, samplerRequestEvent("trace-{$i}")))->toBeFalse()
            ->and($sampler->shouldKeep($project, ['type' => 'log', 'data' => []]))->toBeFalse();
    }
});

it('never samples out exceptions', function () {
    $sampler = new Sampler;

    foreach ([0.0, 0.0001, 0.5] as $rate) {
        $project = Project::factory()->make(['sampling_rate' => $rate]);

        foreach (range(1, 200) as $i) {
            $event = ['type' => 'exception', 'id' => "group-{$i}", 'data' => ['request_id' => "trace-{$i}"]];

            expect($sampler->shouldKeep($project, $event))->toBeTrue();
        }
    }
});

it('keeps a request and all of its children together', function () {
    $project = Project::factory()->make(['sampling_rate' => 0.5]);
    $sampler = new Sampler;

    foreach (range(1, 200) as $i) {
        $traceId = "trace-{$i}";
        $decision = $sampler->shouldKeep($project, samplerRequestEvent($traceId));

        $children = [
            ['type' => 'query', 'id' => 'query-group', 'data' => ['request_id' => $traceId]],
            ['type' => 'log', 'id' => 'other', 'data' => ['request_id' => $traceId]],
            ['type' => 'cache-event', 'id' => 'cache-group', 'data' => ['correlation_id' => $traceId]],
        ];

        foreach ($children as $child) {
            expect($sampler->shouldKeep($project, $child))->toBe($decision);
        }
    }
});

it('falls back to the event id when there is no request or correlation id', function () {
    $project = Project::factory()->make(['sampling_rate' => 0.5]);
    $sampler = new Sampler;

    foreach (range(1, 100) as $i) {
        $event = ['type' => 'job', 'id' => "job-{$i}", 'data' => ['request_id' => null]];

        expect($sampler->shouldKeep($project, $event))->toBe(Sampler::bucket("job-{$i}") < 0.5);
    }
});

it('keeps events that have no id to hash', function () {
    $project = Project::factory()->make(['sampling_rate' => 0.0001]);
    $sampler = new Sampler;

    expect($sampler->shouldKeep($project, ['type' => 'log', 'id' => '', 'data' => ['request_id' => '']]))->toBeTrue()
        ->and($sampler->shouldKeep($project, ['type' => 'log', 'data' => []]))->toBeTrue();
});
