<?php

use App\Jobs\ProcessWatchEvent;
use App\Models\ErrorOccurrence;
use App\Models\LogEntry;
use App\Models\Project;
use App\Models\Trace;
use App\Watch\Sampler;

/**
 * @return array{type: string, id: string, data: array<string, mixed>}
 */
function sampledLogEvent(string $traceId): array
{
    return ['type' => 'log', 'id' => $traceId, 'data' => ['request_id' => $traceId, 'level' => 'info', 'message' => 'hello']];
}

it('stores events when the sampling rate is 1', function () {
    $project = Project::factory()->create(['sampling_rate' => 1.0]);

    ProcessWatchEvent::dispatchSync($project->id, sampledLogEvent('trace-1'));

    expect(LogEntry::where('project_id', $project->id)->count())->toBe(1);
});

it('skips storing an event that is sampled out', function () {
    $project = Project::factory()->create(['sampling_rate' => 0.0]);

    ProcessWatchEvent::dispatchSync($project->id, sampledLogEvent('trace-1'));
    ProcessWatchEvent::dispatchSync($project->id, [
        'type' => 'request',
        'id' => 'trace-1',
        'data' => ['request_id' => 'trace-1', 'method' => 'GET', 'uri' => '/'],
    ]);

    expect(LogEntry::count())->toBe(0)
        ->and(Trace::count())->toBe(0);
});

it('still stores exceptions at a sampling rate of 0', function () {
    $project = Project::factory()->create(['sampling_rate' => 0.0]);

    ProcessWatchEvent::dispatchSync($project->id, [
        'type' => 'exception',
        'id' => 'group-1',
        'data' => ['class' => 'RuntimeException', 'message' => 'boom', 'file' => 'app/A.php', 'line' => 5],
    ]);

    expect(ErrorOccurrence::where('project_id', $project->id)->count())->toBe(1);
});

it('keeps or drops a request and its children together at a partial rate', function () {
    $project = Project::factory()->create(['sampling_rate' => 0.5]);

    $keptId = collect(range(1, 100))->map(fn (int $i) => "trace-{$i}")->first(fn (string $id) => Sampler::bucket($id) < 0.5);
    $droppedId = collect(range(1, 100))->map(fn (int $i) => "trace-{$i}")->first(fn (string $id) => Sampler::bucket($id) >= 0.5);

    foreach ([$keptId, $droppedId] as $traceId) {
        ProcessWatchEvent::dispatchSync($project->id, [
            'type' => 'request',
            'id' => $traceId,
            'data' => ['request_id' => $traceId, 'method' => 'GET', 'uri' => '/'],
        ]);
        ProcessWatchEvent::dispatchSync($project->id, sampledLogEvent($traceId));
    }

    expect(Trace::pluck('correlation_id')->all())->toBe([$keptId])
        ->and(LogEntry::count())->toBe(1);
});
