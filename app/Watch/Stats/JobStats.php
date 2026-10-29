<?php

namespace App\Watch\Stats;

use App\Models\Project;
use App\Models\QueueJobRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class JobStats
{
    /**
     * @return LengthAwarePaginator<array-key, array{job_class:string,queued:int,processed:int,released:int,failed:int,total:int,avg_ms:float|null,p95_ms:float|null}>
     */
    public function paginatedJobs(
        Project $project,
        TimeRange $range,
        ?string $search,
        ?string $sort,
        ?string $dir,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        return StatsPaginator::paginate(
            items: $this->jobs($project, $range, $search),
            sortable: [
                'job_class' => 'string',
                'queued' => 'numeric',
                'processed' => 'numeric',
                'released' => 'numeric',
                'failed' => 'numeric',
                'total' => 'numeric',
                'avg_ms' => 'numeric',
                'p95_ms' => 'numeric',
            ],
            sort: $sort ?? 'job_class',
            dir: $dir ?? 'asc',
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array{
     *   totals: array{total:int,queued:int,processed:int,released:int,failed:int},
     *   duration: array{min_ms: int|null, max_ms: int|null, avg_ms: float|null, p95_ms: float|null, threshold_ms: int|null},
     *   buckets: list<array{bucket:string,processed:int,released:int,failed:int,avg_duration:float|null,p95_duration:float|null}>
     * }
     */
    public function summary(Project $project, TimeRange $range, ?string $jobClass = null): array
    {
        $base = $this->baseQuery($project, $range, $jobClass);

        $stats = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS processed")
            ->selectRaw("SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) AS released")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
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
                'queued' => (int) ($stats?->queued ?? 0),
                'processed' => (int) ($stats?->processed ?? 0),
                'released' => (int) ($stats?->released ?? 0),
                'failed' => (int) ($stats?->failed ?? 0),
            ],
            'duration' => [
                'min_ms' => $stats?->min_duration !== null ? (int) $stats->min_duration : null,
                'max_ms' => $stats?->max_duration !== null ? (int) $stats->max_duration : null,
                'avg_ms' => $stats?->avg_duration !== null ? (float) $stats->avg_duration : null,
                'p95_ms' => $this->percentile($durations, 0.95),
                'threshold_ms' => null,
            ],
            'buckets' => $this->buckets($project, $range, $jobClass),
        ];
    }

    /**
     * @return list<array{job_class:string,queued:int,processed:int,released:int,failed:int,total:int,avg_ms:float|null,p95_ms:float|null}>
     */
    public function jobs(Project $project, TimeRange $range, ?string $search = null): array
    {
        $base = $this->baseQuery($project, $range);

        if ($search !== null && $search !== '') {
            $base->where('job_class', 'like', '%'.$search.'%');
        }

        $rows = (clone $base)
            ->selectRaw('job_class')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'queued' THEN 1 ELSE 0 END) AS queued")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS processed")
            ->selectRaw("SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) AS released")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw('AVG(duration_ms) AS avg_ms')
            ->groupBy('job_class')
            ->get();

        $durationsByClass = $this->durationsByJob($project, $range, $search);

        return $rows
            ->map(function ($row) use ($durationsByClass) {
                $durations = $durationsByClass[$row->job_class] ?? [];

                return [
                    'job_class' => (string) $row->job_class,
                    'queued' => (int) $row->queued,
                    'processed' => (int) $row->processed,
                    'released' => (int) $row->released,
                    'failed' => (int) $row->failed,
                    'total' => (int) $row->total,
                    'avg_ms' => $row->avg_ms !== null ? (float) $row->avg_ms : null,
                    'p95_ms' => $this->percentile($durations, 0.95),
                ];
            })
            ->sortBy('job_class')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   job_class:string,
     *   totals: array{total:int,queued:int,processed:int,released:int,failed:int},
     *   duration: array{min_ms: int|null, max_ms: int|null, avg_ms: float|null, p95_ms: float|null, threshold_ms: int|null},
     *   buckets: list<array{bucket:string,processed:int,released:int,failed:int,avg_duration:float|null,p95_duration:float|null}>,
     *   attempts: list<array{id:string,connection:string|null,queue:string|null,attempt:int,status:string,duration_ms:int|null,lazy_loads:int|null,hydrated_models:int|null,occurred_at:string|null}>
     * }|null
     */
    public function jobDetail(Project $project, TimeRange $range, string $jobClass): ?array
    {
        $base = $this->baseQuery($project, $range, $jobClass);

        if ((clone $base)->doesntExist()) {
            return null;
        }

        $summary = $this->summary($project, $range, $jobClass);

        $attempts = (clone $base)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'connection', 'queue', 'attempts', 'status', 'duration_ms', 'lazy_loads', 'hydrated_models', 'created_at'])
            ->map(fn (QueueJobRun $run) => [
                'id' => $run->id,
                'connection' => $run->connection,
                'queue' => $run->queue,
                'attempt' => (int) $run->attempts,
                'status' => $run->status,
                'duration_ms' => $run->duration_ms,
                'lazy_loads' => $run->lazy_loads,
                'hydrated_models' => $run->hydrated_models,
                'occurred_at' => $run->created_at?->toIso8601String(),
            ])
            ->all();

        return [
            'job_class' => $jobClass,
            'totals' => $summary['totals'],
            'duration' => $summary['duration'],
            'buckets' => $summary['buckets'],
            'attempts' => $attempts,
        ];
    }

    /**
     * @return Builder<QueueJobRun>
     */
    private function baseQuery(Project $project, TimeRange $range, ?string $jobClass = null): Builder
    {
        $query = QueueJobRun::query()
            ->where('project_id', $project->id)
            ->whereBetween('created_at', [$range->from, $range->to]);

        if ($jobClass !== null) {
            $query->where('job_class', $jobClass);
        }

        return $query;
    }

    /**
     * @return list<array{bucket:string,processed:int,released:int,failed:int,avg_duration:float|null,p95_duration:float|null}>
     */
    private function buckets(Project $project, TimeRange $range, ?string $jobClass = null): array
    {
        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));

        $rows = $this->baseQuery($project, $range, $jobClass)
            ->orderBy('created_at')
            ->get(['status', 'duration_ms', 'created_at']);

        $start = $range->from->getTimestamp();
        $buckets = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $buckets[$i] = [
                'bucket' => CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String(),
                'processed' => 0,
                'released' => 0,
                'failed' => 0,
                'durations' => [],
            ];
        }

        foreach ($rows as $row) {
            $offset = $row->created_at->getTimestamp() - $start;
            $idx = max(0, min($bucketCount - 1, intdiv($offset, $bucketSeconds)));

            if ($row->status === 'failed') {
                $buckets[$idx]['failed']++;
            } elseif ($row->status === 'released') {
                $buckets[$idx]['released']++;
            } elseif ($row->status === 'completed') {
                $buckets[$idx]['processed']++;
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
                'processed' => $bucket['processed'],
                'released' => $bucket['released'],
                'failed' => $bucket['failed'],
                'avg_duration' => $durations === [] ? null : array_sum($durations) / count($durations),
                'p95_duration' => $this->percentile($durations, 0.95),
            ];
        }, $buckets);
    }

    /**
     * @return array<string, list<int>>
     */
    private function durationsByJob(Project $project, TimeRange $range, ?string $search): array
    {
        $query = $this->baseQuery($project, $range)->whereNotNull('duration_ms');

        if ($search !== null && $search !== '') {
            $query->where('job_class', 'like', '%'.$search.'%');
        }

        $buckets = [];
        $query->orderBy('duration_ms')
            ->get(['job_class', 'duration_ms'])
            ->each(function (QueueJobRun $run) use (&$buckets) {
                $buckets[$run->job_class][] = (int) $run->duration_ms;
            });

        return $buckets;
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
