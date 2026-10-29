<?php

namespace App\Watch\Stats;

use App\Models\Project;
use App\Models\Trace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class RequestStats
{
    /**
     * The per-stage timing columns of a request, in the order the request runs through them.
     */
    private const STAGE_COLUMNS = [
        'bootstrap_ms',
        'before_middleware_ms',
        'action_ms',
        'render_ms',
        'after_middleware_ms',
        'sending_ms',
        'terminating_ms',
    ];

    /**
     * @return array{
     *   totals: array{total:int, success:int, client_error:int, server_error:int},
     *   duration: array{min_ms: int|null, max_ms: int|null, avg_ms: float|null, p95_ms: float|null},
     *   buckets: list<array{bucket:string,success:int,client_error:int,server_error:int,avg_duration:float|null,p95_duration:float|null}>
     * }
     */
    public function summary(Project $project, TimeRange $range, ?string $userId = null): array
    {
        $base = $this->baseQuery($project, $range, $userId);

        $stats = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) AS success')
            ->selectRaw('SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) AS client_error')
            ->selectRaw('SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) AS server_error')
            ->selectRaw('MIN(duration_ms) AS min_duration')
            ->selectRaw('MAX(duration_ms) AS max_duration')
            ->selectRaw('AVG(duration_ms) AS avg_duration')
            ->first();

        $durations = (clone $base)
            ->whereNotNull('duration_ms')
            ->pluck('duration_ms')
            ->all();

        return [
            'totals' => [
                'total' => (int) ($stats?->total ?? 0),
                'success' => (int) ($stats?->success ?? 0),
                'client_error' => (int) ($stats?->client_error ?? 0),
                'server_error' => (int) ($stats?->server_error ?? 0),
            ],
            'duration' => [
                'min_ms' => $stats?->min_duration !== null ? (int) $stats->min_duration : null,
                'max_ms' => $stats?->max_duration !== null ? (int) $stats->max_duration : null,
                'avg_ms' => $stats?->avg_duration !== null ? (float) $stats->avg_duration : null,
                'p95_ms' => $this->percentile($durations, 0.95),
            ],
            'buckets' => $this->buckets($project, $range, $userId),
        ];
    }

    /**
     * @return list<array{method:string,uri:string,route_name:string|null,route_action:string|null,success:int,client_error:int,server_error:int,total:int,avg_ms:float|null,p95_ms:float|null}>
     */
    public function routes(Project $project, TimeRange $range, ?string $userId = null, ?string $search = null): array
    {
        $base = $this->baseQuery($project, $range, $userId);

        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $base->where(function (Builder $query) use ($like) {
                $query->where('uri', 'like', $like)->orWhere('method', 'like', $like)->orWhere('route_name', 'like', $like);
            });
        }

        $rows = (clone $base)
            ->selectRaw('method, uri')
            ->selectRaw('MAX(route_name) AS route_name')
            ->selectRaw('MAX(route_action) AS route_action')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) AS success')
            ->selectRaw('SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) AS client_error')
            ->selectRaw('SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) AS server_error')
            ->selectRaw('AVG(duration_ms) AS avg_ms')
            ->groupBy('method', 'uri')
            ->get();

        $durationsPerKey = $this->durationsByRoute($project, $range, $userId, $search);

        return $rows
            ->map(function ($row) use ($durationsPerKey) {
                $key = $row->method.'|'.$row->uri;
                $durations = $durationsPerKey[$key] ?? [];

                return [
                    'method' => (string) $row->method,
                    'uri' => (string) $row->uri,
                    'route_name' => $row->route_name !== null ? (string) $row->route_name : null,
                    'route_action' => $row->route_action !== null ? (string) $row->route_action : null,
                    'success' => (int) $row->success,
                    'client_error' => (int) $row->client_error,
                    'server_error' => (int) $row->server_error,
                    'total' => (int) $row->total,
                    'avg_ms' => $row->avg_ms !== null ? (float) $row->avg_ms : null,
                    'p95_ms' => $this->percentile($durations, 0.95),
                ];
            })
            ->sortBy('uri')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   method:string, uri:string,
     *   route: array{name: string|null, action: string|null, domain: string|null},
     *   stages: array{bootstrap: float|null, before_middleware: float|null, action: float|null, render: float|null, after_middleware: float|null, sending: float|null, terminating: float|null},
     *   sizes: array{avg_request_bytes: float|null, avg_response_bytes: float|null},
     *   totals: array{total:int, success:int, client_error:int, server_error:int},
     *   duration: array{avg_ms: float|null, p95_ms: float|null},
     *   buckets: list<array{bucket:string,success:int,client_error:int,server_error:int,avg_duration:float|null,p95_duration:float|null}>,
     *   recent: list<array{id:string,status_code:int|null,duration_ms:int|null,stages: array{bootstrap: float|null, before_middleware: float|null, action: float|null, render: float|null, after_middleware: float|null, sending: float|null, terminating: float|null},occurred_at:string|null,user_identifier:string|null}>
     * }|null
     */
    public function routeDetail(Project $project, TimeRange $range, string $method, string $uri, ?string $userId = null): ?array
    {
        $base = $this->baseQuery($project, $range, $userId)
            ->where('method', $method)
            ->where('uri', $uri);

        if ((clone $base)->doesntExist()) {
            return null;
        }

        $stats = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) AS success')
            ->selectRaw('SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) AS client_error')
            ->selectRaw('SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) AS server_error')
            ->selectRaw('AVG(duration_ms) AS avg_ms')
            ->first();

        $durations = (clone $base)
            ->whereNotNull('duration_ms')
            ->pluck('duration_ms')
            ->all();

        $details = (clone $base)
            ->selectRaw('MAX(route_name) AS route_name')
            ->selectRaw('MAX(route_action) AS route_action')
            ->selectRaw('MAX(route_domain) AS route_domain')
            ->selectRaw('AVG(request_size_bytes) AS avg_request_bytes')
            ->selectRaw('AVG(response_size_bytes) AS avg_response_bytes')
            ->first();

        // Averaged only over requests that carry stage data, so the proportions come from one consistent set.
        $stageQuery = clone $base;
        foreach (self::STAGE_COLUMNS as $column) {
            $stageQuery->whereNotNull($column)->selectRaw("AVG({$column}) AS {$column}");
        }
        $stageAverages = $stageQuery->first();

        $recent = (clone $base)
            ->orderByDesc('occurred_at')
            ->limit(20)
            ->get(['id', 'method', 'uri', 'status_code', 'duration_ms', ...self::STAGE_COLUMNS, 'occurred_at', 'user_identifier'])
            ->map(fn (Trace $trace) => [
                'id' => $trace->id,
                'method' => $trace->method,
                'uri' => $trace->uri,
                'status_code' => $trace->status_code,
                'duration_ms' => $trace->duration_ms,
                'stages' => $this->stages($trace),
                'occurred_at' => $trace->occurred_at?->toIso8601String(),
                'user_identifier' => $trace->user_identifier,
            ])
            ->all();

        return [
            'method' => $method,
            'uri' => $uri,
            'route' => [
                'name' => $details?->route_name !== null ? (string) $details->route_name : null,
                'action' => $details?->route_action !== null ? (string) $details->route_action : null,
                'domain' => $details?->route_domain !== null ? (string) $details->route_domain : null,
            ],
            'stages' => $this->stages($stageAverages),
            'sizes' => [
                'avg_request_bytes' => $details?->avg_request_bytes !== null ? (float) $details->avg_request_bytes : null,
                'avg_response_bytes' => $details?->avg_response_bytes !== null ? (float) $details->avg_response_bytes : null,
            ],
            'totals' => [
                'total' => (int) ($stats?->total ?? 0),
                'success' => (int) ($stats?->success ?? 0),
                'client_error' => (int) ($stats?->client_error ?? 0),
                'server_error' => (int) ($stats?->server_error ?? 0),
            ],
            'duration' => [
                'avg_ms' => $stats?->avg_ms !== null ? (float) $stats->avg_ms : null,
                'p95_ms' => $this->percentile($durations, 0.95),
            ],
            'buckets' => $this->buckets($project, $range, $userId, $method, $uri),
            'recent' => $recent,
        ];
    }

    /**
     * @return list<array{id:string,email:string|null,count:int}>
     */
    public function topUsers(Project $project, TimeRange $range, int $limit = 30): array
    {
        return Trace::query()
            ->excludingStubs()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->whereNotNull('user_identifier')
            ->selectRaw('user_identifier AS id, MAX(user_email) AS email, COUNT(*) AS count')
            ->groupBy('user_identifier')
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'email' => $row->email !== null ? (string) $row->email : null,
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @return Builder<Trace>
     */
    private function baseQuery(Project $project, TimeRange $range, ?string $userId): Builder
    {
        $query = Trace::query()
            ->excludingStubs()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to]);

        if ($userId !== null && $userId !== '') {
            $query->where('user_identifier', $userId);
        }

        return $query;
    }

    /**
     * @return list<array{bucket:string,success:int,client_error:int,server_error:int,avg_duration:float|null,p95_duration:float|null}>
     */
    private function buckets(Project $project, TimeRange $range, ?string $userId, ?string $method = null, ?string $uri = null): array
    {
        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));

        $query = $this->baseQuery($project, $range, $userId);
        if ($method !== null) {
            $query->where('method', $method);
        }
        if ($uri !== null) {
            $query->where('uri', $uri);
        }

        $rows = $query
            ->orderBy('occurred_at')
            ->get(['status_code', 'duration_ms', 'occurred_at']);

        $start = $range->from->getTimestamp();
        $buckets = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $buckets[$i] = [
                'bucket' => CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String(),
                'success' => 0,
                'client_error' => 0,
                'server_error' => 0,
                'durations' => [],
            ];
        }

        foreach ($rows as $row) {
            $offset = $row->occurred_at->getTimestamp() - $start;
            $idx = max(0, min($bucketCount - 1, intdiv($offset, $bucketSeconds)));

            $code = (int) ($row->status_code ?? 0);
            if ($code >= 500) {
                $buckets[$idx]['server_error']++;
            } elseif ($code >= 400) {
                $buckets[$idx]['client_error']++;
            } else {
                $buckets[$idx]['success']++;
            }

            if ($row->duration_ms !== null) {
                $buckets[$idx]['durations'][] = (int) $row->duration_ms;
            }
        }

        return array_map(function (array $bucket): array {
            $durations = $bucket['durations'];
            sort($durations);

            return [
                'bucket' => $bucket['bucket'],
                'success' => $bucket['success'],
                'client_error' => $bucket['client_error'],
                'server_error' => $bucket['server_error'],
                'avg_duration' => $durations === [] ? null : array_sum($durations) / count($durations),
                'p95_duration' => $this->percentile($durations, 0.95),
            ];
        }, $buckets);
    }

    /**
     * @return array<string, list<int>>
     */
    private function durationsByRoute(Project $project, TimeRange $range, ?string $userId, ?string $search): array
    {
        $query = $this->baseQuery($project, $range, $userId)->whereNotNull('duration_ms');

        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $sub) use ($like) {
                $sub->where('uri', 'like', $like)->orWhere('method', 'like', $like)->orWhere('route_name', 'like', $like);
            });
        }

        $buckets = [];
        $query->orderBy('duration_ms')
            ->get(['method', 'uri', 'duration_ms'])
            ->each(function (Trace $trace) use (&$buckets) {
                $key = $trace->method.'|'.$trace->uri;
                $buckets[$key][] = (int) $trace->duration_ms;
            });

        return $buckets;
    }

    /**
     * The stage timings of one trace, or of a row of per-stage averages. MySQL returns an AVG over a DECIMAL column
     * as a string, hence the explicit cast.
     *
     * @return array{bootstrap: float|null, before_middleware: float|null, action: float|null, render: float|null, after_middleware: float|null, sending: float|null, terminating: float|null}
     */
    private function stages(?Trace $row): array
    {
        $stage = fn (string $column): ?float => $row?->{$column} !== null ? (float) $row->{$column} : null;

        return [
            'bootstrap' => $stage('bootstrap_ms'),
            'before_middleware' => $stage('before_middleware_ms'),
            'action' => $stage('action_ms'),
            'render' => $stage('render_ms'),
            'after_middleware' => $stage('after_middleware_ms'),
            'sending' => $stage('sending_ms'),
            'terminating' => $stage('terminating_ms'),
        ];
    }

    /**
     * @param  list<int|float>  $values
     */
    private function percentile(array $values, float $p): ?float
    {
        if ($values === []) {
            return null;
        }
        $sorted = $values;
        sort($sorted);

        $index = (int) floor($p * (count($sorted) - 1));

        return (float) $sorted[$index];
    }
}
