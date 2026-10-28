<?php

namespace App\Jobs;

use App\Models\Project;
use App\Watch\EventStore;
use App\Watch\Sampler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessWatchEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 5;

    /**
     * @param  array{type: string, id?: string, data: array<string, mixed>}  $event
     */
    public function __construct(
        public readonly string $projectId,
        public readonly array $event,
    ) {
        // $this->onQueue('watch-events');
    }

    /**
     * Store the event unless the project's sampling rate drops it.
     *
     * Sampling is applied here, in the worker, so an event that is sampled
     * out still costs a queue round trip. The decision depends only on the
     * event and the project's rate, so it could later move before dispatch
     * (in the ingest controllers) to avoid queueing dropped events at all.
     */
    public function handle(EventStore $store, Sampler $sampler): void
    {
        $project = Project::find($this->projectId);

        if (! $project) {
            return;
        }

        if (! $sampler->shouldKeep($project, $this->event)) {
            return;
        }

        $store->store($project, $this->event);
    }
}
