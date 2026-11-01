<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Watch\Stats\QueryStats;
use App\Watch\Stats\TimeRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class QueriesController extends Controller
{
    public function index(Project $project, Request $request, QueryStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '24h')->toString());
        $search = trim($request->string('search')->toString()) ?: null;
        $connection = trim($request->string('connection')->toString()) ?: null;
        $sort = $request->string('sort')->toString() ?: null;
        $dir = $request->string('dir')->toString() ?: null;
        $page = max(1, (int) $request->integer('page', 1));

        return Inertia::render('projects/queries/index', [
            'summary' => $stats->summary($project, $range, connection: $connection),
            'queries' => $stats->paginatedQueries($project, $range, $search, $sort, $dir, $page, 25, connection: $connection),
            'connections' => $stats->connections($project, $range),
            'selectedRange' => $range->label,
            'filters' => [
                'search' => $search,
                'connection' => $connection,
                'sort' => $sort ?? 'total_ms',
                'dir' => $dir ?? 'desc',
            ],
        ]);
    }

    public function show(Project $project, Request $request, QueryStats $stats, string $query): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '7d')->toString());

        $detail = $stats->queryDetail($project, $range, $query);

        abort_if($detail === null, 404);

        return Inertia::render('projects/queries/show', [
            'detail' => $detail,
            'selectedRange' => $range->label,
        ]);
    }
}
