<?php

namespace App\Watch\Stats;

use App\Models\ErrorOccurrence;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\Trace;
use Carbon\CarbonImmutable;

class UserStats
{
    /**
     * @return array{
     *   authenticated_users:int,
     *   total_requests:int,
     *   authenticated_requests:int,
     *   guest_requests:int,
     *   user_buckets: list<array{bucket:string,count:int}>,
     *   request_buckets: list<array{bucket:string,authenticated:int,guest:int}>
     * }
     */
    public function summary(Project $project, TimeRange $range): array
    {
        $base = Trace::query()
            ->excludingStubs()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to]);

        $totals = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN user_identifier IS NOT NULL THEN 1 ELSE 0 END) AS authenticated')
            ->selectRaw('SUM(CASE WHEN user_identifier IS NULL THEN 1 ELSE 0 END) AS guest')
            ->selectRaw('COUNT(DISTINCT user_identifier) AS users')
            ->first();

        $rows = (clone $base)
            ->orderBy('occurred_at')
            ->get(['user_identifier', 'occurred_at']);

        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));
        $start = $range->from->getTimestamp();

        $userBuckets = [];
        $requestBuckets = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $time = CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String();
            $userBuckets[$i] = ['bucket' => $time, 'distinct' => [], 'count' => 0];
            $requestBuckets[$i] = ['bucket' => $time, 'authenticated' => 0, 'guest' => 0];
        }

        foreach ($rows as $row) {
            $offset = $row->occurred_at->getTimestamp() - $start;
            $idx = max(0, min($bucketCount - 1, intdiv($offset, $bucketSeconds)));
            $userId = $row->user_identifier;

            if ($userId !== null) {
                if (! isset($userBuckets[$idx]['distinct'][$userId])) {
                    $userBuckets[$idx]['distinct'][$userId] = true;
                    $userBuckets[$idx]['count']++;
                }
                $requestBuckets[$idx]['authenticated']++;
            } else {
                $requestBuckets[$idx]['guest']++;
            }
        }

        return [
            'authenticated_users' => (int) ($totals?->users ?? 0),
            'total_requests' => (int) ($totals?->total ?? 0),
            'authenticated_requests' => (int) ($totals?->authenticated ?? 0),
            'guest_requests' => (int) ($totals?->guest ?? 0),
            'user_buckets' => array_map(
                fn (array $b): array => ['bucket' => $b['bucket'], 'count' => $b['count']],
                $userBuckets,
            ),
            'request_buckets' => array_map(
                fn (array $b): array => ['bucket' => $b['bucket'], 'authenticated' => $b['authenticated'], 'guest' => $b['guest']],
                $requestBuckets,
            ),
        ];
    }

    /**
     * @return list<array{
     *   id:string, email:string|null, name:string|null,
     *   requests_total:int, requests_2xx:int, requests_4xx:int, requests_5xx:int,
     *   queued_jobs:int, exceptions:int, last_seen:string|null
     * }>
     */
    public function list(Project $project, TimeRange $range, ?string $search = null): array
    {
        $trace = Trace::query()
            ->excludingStubs()
            ->where('project_id', $project->id)
            ->whereNotNull('user_identifier')
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->selectRaw('user_identifier AS id, MAX(user_email) AS email, MAX(user_name) AS name')
            ->selectRaw('COUNT(*) AS requests_total')
            ->selectRaw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) AS requests_2xx')
            ->selectRaw('SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) AS requests_4xx')
            ->selectRaw('SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) AS requests_5xx')
            ->selectRaw('MAX(occurred_at) AS last_seen')
            ->groupBy('user_identifier')
            ->get()
            ->keyBy('id');

        $jobs = QueueJobRun::query()
            ->where('project_id', $project->id)
            ->whereNotNull('user_identifier')
            ->whereBetween('dispatched_at', [$range->from, $range->to])
            ->selectRaw('user_identifier AS id, COUNT(*) AS queued_jobs')
            ->groupBy('user_identifier')
            ->pluck('queued_jobs', 'id');

        $errors = ErrorOccurrence::query()
            ->where('project_id', $project->id)
            ->whereNotNull('user_identifier')
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->selectRaw('user_identifier AS id, COUNT(*) AS exceptions')
            ->groupBy('user_identifier')
            ->pluck('exceptions', 'id');

        $rows = [];
        foreach ($trace as $id => $row) {
            $name = $row->name !== null ? (string) $row->name : null;
            $email = $row->email !== null ? (string) $row->email : null;

            if ($search !== null && $search !== '') {
                $needle = strtolower($search);
                $haystack = strtolower(($name ?? '').' '.($email ?? ''));
                if (! str_contains($haystack, $needle)) {
                    continue;
                }
            }

            $rows[] = [
                'id' => (string) $id,
                'email' => $email,
                'name' => $name,
                'requests_total' => (int) $row->requests_total,
                'requests_2xx' => (int) $row->requests_2xx,
                'requests_4xx' => (int) $row->requests_4xx,
                'requests_5xx' => (int) $row->requests_5xx,
                'queued_jobs' => (int) ($jobs[$id] ?? 0),
                'exceptions' => (int) ($errors[$id] ?? 0),
                'last_seen' => $row->last_seen !== null ? CarbonImmutable::parse($row->last_seen)->toIso8601String() : null,
            ];
        }

        usort($rows, fn (array $a, array $b): int => $b['requests_total'] <=> $a['requests_total']);

        return $rows;
    }

    /**
     * @return array{
     *   id:string, email:string|null, name:string|null, last_seen:string|null,
     *   summary: array{total:int, success:int, client_error:int, server_error:int},
     *   request_buckets: list<array{bucket:string,success:int,client_error:int,server_error:int}>,
     *   top_routes: list<array{method:string,uri:string,count:int}>,
     *   slowest_routes: list<array{method:string,uri:string,p95_ms:float|null,count:int}>,
     *   top_jobs: list<array{job_class:string,count:int}>,
     *   recent_requests: list<array{id:string,method:string,uri:string,status_code:int|null,duration_ms:int|null,occurred_at:string|null}>
     * }|null
     */
    public function detail(Project $project, string $userIdentifier, TimeRange $range): ?array
    {
        $base = Trace::query()
            ->excludingStubs()
            ->where('project_id', $project->id)
            ->where('user_identifier', $userIdentifier)
            ->whereBetween('occurred_at', [$range->from, $range->to]);

        $profile = Trace::query()
            ->excludingStubs()
            ->where('project_id', $project->id)
            ->where('user_identifier', $userIdentifier)
            ->orderByDesc('occurred_at')
            ->first(['user_identifier', 'user_email', 'user_name', 'occurred_at']);

        if ($profile === null) {
            return null;
        }

        $totals = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) AS success')
            ->selectRaw('SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) AS client_error')
            ->selectRaw('SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) AS server_error')
            ->first();

        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));
        $start = $range->from->getTimestamp();

        $buckets = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $buckets[$i] = [
                'bucket' => CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String(),
                'success' => 0,
                'client_error' => 0,
                'server_error' => 0,
            ];
        }

        $rows = (clone $base)->orderBy('occurred_at')->get(['status_code', 'occurred_at']);
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
        }

        $topRoutes = (clone $base)
            ->selectRaw('method, uri, COUNT(*) AS count')
            ->groupBy('method', 'uri')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'method' => (string) $row->method,
                'uri' => (string) $row->uri,
                'count' => (int) $row->count,
            ])
            ->all();

        $durationsByRoute = (clone $base)
            ->whereNotNull('duration_ms')
            ->orderBy('duration_ms')
            ->get(['method', 'uri', 'duration_ms'])
            ->groupBy(fn ($r): string => $r->method.'|'.$r->uri);

        $slowestRoutes = $durationsByRoute
            ->map(function ($items, $key): array {
                [$method, $uri] = explode('|', (string) $key, 2);
                $values = $items->pluck('duration_ms')->map(fn ($v): int => (int) $v)->all();
                sort($values);
                $idx = (int) floor(0.95 * (count($values) - 1));

                return [
                    'method' => $method,
                    'uri' => $uri,
                    'p95_ms' => $values === [] ? null : (float) $values[$idx],
                    'count' => count($values),
                ];
            })
            ->sortByDesc('p95_ms')
            ->values()
            ->take(5)
            ->all();

        $topJobs = QueueJobRun::query()
            ->where('project_id', $project->id)
            ->where('user_identifier', $userIdentifier)
            ->whereBetween('dispatched_at', [$range->from, $range->to])
            ->selectRaw('job_class, COUNT(*) AS count')
            ->groupBy('job_class')
            ->orderByDesc('count')
            ->limit(5)
            ->get()
            ->map(fn ($row): array => [
                'job_class' => (string) $row->job_class,
                'count' => (int) $row->count,
            ])
            ->all();

        $recent = (clone $base)
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get(['id', 'method', 'uri', 'status_code', 'duration_ms', 'occurred_at'])
            ->map(fn (Trace $t): array => [
                'id' => (string) $t->id,
                'method' => (string) $t->method,
                'uri' => (string) $t->uri,
                'status_code' => $t->status_code,
                'duration_ms' => $t->duration_ms,
                'occurred_at' => $t->occurred_at?->toIso8601String(),
            ])
            ->all();

        return [
            'id' => (string) $profile->user_identifier,
            'email' => $profile->user_email !== null ? (string) $profile->user_email : null,
            'name' => $profile->user_name !== null ? (string) $profile->user_name : null,
            'last_seen' => $profile->occurred_at?->toIso8601String(),
            'summary' => [
                'total' => (int) ($totals?->total ?? 0),
                'success' => (int) ($totals?->success ?? 0),
                'client_error' => (int) ($totals?->client_error ?? 0),
                'server_error' => (int) ($totals?->server_error ?? 0),
            ],
            'request_buckets' => $buckets,
            'top_routes' => $topRoutes,
            'slowest_routes' => $slowestRoutes,
            'top_jobs' => $topJobs,
            'recent_requests' => $recent,
        ];
    }
}
