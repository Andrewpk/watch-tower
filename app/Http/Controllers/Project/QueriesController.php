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
        $range = TimeRange::fromLabel($this->stringFilter($request, 'range') ?? '24h');
        $search = $this->stringFilter($request, 'search');
        $connection = $this->stringFilter($request, 'connection');
        $sort = $this->stringFilter($request, 'sort');
        $dir = $this->stringFilter($request, 'dir');
        $page = max(1, (int) $request->integer('page', 1));

        return Inertia::render('projects/queries/index', [
            'summary' => $stats->summary($project, $range, connection: $connection),
            'queries' => $stats->paginatedQueries($project, $range, $search, $sort, $dir, $page, 25, connection: $connection),
            'connections' => $stats->connections($project, $range, $connection),
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
        $range = TimeRange::fromLabel($this->stringFilter($request, 'range') ?? '7d');
        $connection = $this->stringFilter($request, 'connection');

        $detail = $stats->queryDetail($project, $range, $query, $connection);

        abort_if($detail === null, 404);

        return Inertia::render('projects/queries/show', [
            'detail' => $detail,
            'selectedRange' => $range->label,
            'selectedConnection' => $connection,
        ]);
    }

    /**
     * A filter from the query string, or null when it is missing, blank or not a string (such as `?connection[]=x`).
     */
    private function stringFilter(Request $request, string $key): ?string
    {
        $value = $request->input($key);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
