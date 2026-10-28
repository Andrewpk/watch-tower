<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\IngestBatchRequest;
use App\Jobs\ProcessWatchEvent;
use App\Models\Project;
use App\Watch\EventStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class IngestController extends Controller
{
    public function batch(IngestBatchRequest $request): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('watch_project');

        $events = $request->validated('events');

        foreach ($events as $event) {
            ProcessWatchEvent::dispatch($project->id, $event);
        }

        return response()->json([
            'success' => true,
            'batch_id' => $request->validated('batch_id') ?? (string) Str::uuid(),
            'events_received' => count($events),
            'events_queued' => count($events),
        ], 202);
    }

    public function sync(IngestBatchRequest $request, EventStore $store): JsonResponse
    {
        /** @var Project $project */
        $project = $request->attributes->get('watch_project');

        $events = $request->validated('events');

        foreach ($events as $event) {
            $store->store($project, $event);
        }

        return response()->json([
            'success' => true,
            'events_received' => count($events),
            'events_stored' => count($events),
        ]);
    }
}
