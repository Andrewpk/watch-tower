<?php

namespace App\Watch\Stats;

use App\Models\Project;
use App\Models\ScheduledTaskRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class ScheduledTaskStats
{
    /**
     * @return LengthAwarePaginator<array-key, array{task:string,task_hash:string,schedule:string|null,next_run_at:string|null,processed:int,skipped:int,failed:int,total:int,avg_ms:float|null,p95_ms:float|null}>
     */
    public function paginatedTasks(
        Project $project,
        TimeRange $range,
        ?string $search,
        ?string $sort,
        ?string $dir,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        return StatsPaginator::paginate(
            items: $this->tasks($project, $range, $search),
            sortable: [
                'task' => 'string',
                'processed' => 'numeric',
                'skipped' => 'numeric',
                'failed' => 'numeric',
                'total' => 'numeric',
                'avg_ms' => 'numeric',
                'p95_ms' => 'numeric',
            ],
            sort: $sort ?? 'task',
            dir: $dir ?? 'asc',
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array{
     *   totals: array{total:int,processed:int,skipped:int,failed:int},
     *   duration: array{min_ms:int|null,max_ms:int|null,avg_ms:float|null,p95_ms:float|null,threshold_ms:int|null},
     *   buckets: list<array{bucket:string,processed:int,skipped:int,failed:int,avg_duration:float|null,p95_duration:float|null}>
     * }
     */
    public function summary(Project $project, TimeRange $range, ?string $taskHash = null): array
    {
        $base = $this->baseQuery($project, $range, $taskHash);

        $stats = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) AS processed")
            ->selectRaw("SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw('MIN(duration_ms) AS min_duration')
            ->selectRaw('MAX(duration_ms) AS max_duration')
            ->selectRaw('AVG(duration_ms) AS avg_duration')
            ->selectRaw('MAX(threshold_ms) AS threshold')
            ->first();

        $durations = (clone $base)
            ->whereNotNull('duration_ms')
            ->pluck('duration_ms')
            ->all();

        return [
            'totals' => [
                'total' => (int) ($stats?->total ?? 0),
                'processed' => (int) ($stats?->processed ?? 0),
                'skipped' => (int) ($stats?->skipped ?? 0),
                'failed' => (int) ($stats?->failed ?? 0),
            ],
            'duration' => [
                'min_ms' => $stats?->min_duration !== null ? (int) $stats->min_duration : null,
                'max_ms' => $stats?->max_duration !== null ? (int) $stats->max_duration : null,
                'avg_ms' => $stats?->avg_duration !== null ? (float) $stats->avg_duration : null,
                'p95_ms' => $this->percentile($durations, 0.95),
                'threshold_ms' => $stats?->threshold !== null ? (int) $stats->threshold : null,
            ],
            'buckets' => $this->buckets($project, $range, $taskHash),
        ];
    }

    /**
     * @return list<array{task:string,task_hash:string,schedule:string|null,next_run_at:string|null,processed:int,skipped:int,failed:int,total:int,avg_ms:float|null,p95_ms:float|null}>
     */
    public function tasks(Project $project, TimeRange $range, ?string $search = null): array
    {
        $base = $this->baseQuery($project, $range);

        if ($search !== null && $search !== '') {
            $base->where('task', 'like', '%'.$search.'%');
        }

        $rows = (clone $base)
            ->selectRaw('task')
            ->selectRaw('task_hash')
            ->selectRaw('MAX(schedule) AS schedule')
            ->selectRaw('MAX(next_run_at) AS next_run_at')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'processed' THEN 1 ELSE 0 END) AS processed")
            ->selectRaw("SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) AS skipped")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw('AVG(duration_ms) AS avg_ms')
            ->groupBy('task', 'task_hash')
            ->get();

        $durationsByTask = $this->durationsByTask($project, $range, $search);

        return $rows
            ->map(function ($row) use ($durationsByTask) {
                $durations = $durationsByTask[$row->task_hash] ?? [];

                return [
                    'task' => (string) $row->task,
                    'task_hash' => (string) $row->task_hash,
                    'schedule' => $row->schedule !== null ? (string) $row->schedule : null,
                    'next_run_at' => $row->next_run_at !== null
                        ? CarbonImmutable::parse($row->next_run_at)->toIso8601String()
                        : null,
                    'processed' => (int) $row->processed,
                    'skipped' => (int) $row->skipped,
                    'failed' => (int) $row->failed,
                    'total' => (int) $row->total,
                    'avg_ms' => $row->avg_ms !== null ? (float) $row->avg_ms : null,
                    'p95_ms' => $this->percentile($durations, 0.95),
                ];
            })
            ->sortBy('task')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   task:string,
     *   task_hash:string,
     *   schedule:string|null,
     *   schedule_summary:string|null,
     *   next_run_at:string|null,
     *   options: array{timezone:string|null,repeat_seconds:int|null,without_overlapping:bool|null,on_one_server:bool|null,run_in_background:bool|null,even_in_maintenance_mode:bool|null},
     *   totals: array{total:int,processed:int,skipped:int,failed:int},
     *   duration: array{min_ms:int|null,max_ms:int|null,avg_ms:float|null,p95_ms:float|null,threshold_ms:int|null},
     *   buckets: list<array{bucket:string,processed:int,skipped:int,failed:int,avg_duration:float|null,p95_duration:float|null}>,
     *   runs: list<array{id:string,memory_peak_kb:int|null,status:string,duration_ms:int|null,exit_code:int|null,lazy_loads:int|null,hydrated_models:int|null,occurred_at:string|null}>
     * }|null
     */
    public function taskDetail(Project $project, TimeRange $range, string $taskHash): ?array
    {
        $base = $this->baseQuery($project, $range, $taskHash);

        $latest = (clone $base)->orderByDesc('occurred_at')->first();

        if ($latest === null) {
            return null;
        }

        $summary = $this->summary($project, $range, $taskHash);

        $runs = (clone $base)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get(['id', 'memory_peak_kb', 'status', 'duration_ms', 'exit_code', 'lazy_loads', 'hydrated_models', 'occurred_at'])
            ->map(fn (ScheduledTaskRun $run) => [
                'id' => $run->id,
                'memory_peak_kb' => $run->memory_peak_kb,
                'status' => $run->status,
                'duration_ms' => $run->duration_ms,
                'exit_code' => $run->exit_code,
                'lazy_loads' => $run->lazy_loads,
                'hydrated_models' => $run->hydrated_models,
                'occurred_at' => $run->occurred_at?->toIso8601String(),
            ])
            ->all();

        return [
            'task' => $latest->task,
            'task_hash' => $latest->task_hash,
            'schedule' => $latest->schedule,
            'schedule_summary' => $latest->schedule_summary,
            'next_run_at' => $latest->next_run_at?->toIso8601String(),
            'options' => [
                'timezone' => $latest->timezone,
                'repeat_seconds' => $latest->repeat_seconds,
                'without_overlapping' => $latest->without_overlapping,
                'on_one_server' => $latest->on_one_server,
                'run_in_background' => $latest->run_in_background,
                'even_in_maintenance_mode' => $latest->even_in_maintenance_mode,
            ],
            'totals' => $summary['totals'],
            'duration' => $summary['duration'],
            'buckets' => $summary['buckets'],
            'runs' => $runs,
        ];
    }

    /**
     * @return Builder<ScheduledTaskRun>
     */
    private function baseQuery(Project $project, TimeRange $range, ?string $taskHash = null): Builder
    {
        $query = ScheduledTaskRun::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to]);

        if ($taskHash !== null) {
            $query->where('task_hash', $taskHash);
        }

        return $query;
    }

    /**
     * @return list<array{bucket:string,processed:int,skipped:int,failed:int,avg_duration:float|null,p95_duration:float|null}>
     */
    private function buckets(Project $project, TimeRange $range, ?string $taskHash = null): array
    {
        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));

        $rows = $this->baseQuery($project, $range, $taskHash)
            ->orderBy('occurred_at')
            ->get(['status', 'duration_ms', 'occurred_at']);

        $start = $range->from->getTimestamp();
        $buckets = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $buckets[$i] = [
                'bucket' => CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String(),
                'processed' => 0,
                'skipped' => 0,
                'failed' => 0,
                'durations' => [],
            ];
        }

        foreach ($rows as $row) {
            $offset = $row->occurred_at->getTimestamp() - $start;
            $idx = max(0, min($bucketCount - 1, intdiv($offset, $bucketSeconds)));

            if ($row->status === 'failed') {
                $buckets[$idx]['failed']++;
            } elseif ($row->status === 'skipped') {
                $buckets[$idx]['skipped']++;
            } elseif ($row->status === 'processed') {
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
                'skipped' => $bucket['skipped'],
                'failed' => $bucket['failed'],
                'avg_duration' => $durations === [] ? null : array_sum($durations) / count($durations),
                'p95_duration' => $this->percentile($durations, 0.95),
            ];
        }, $buckets);
    }

    /**
     * @return array<string, list<int>>
     */
    private function durationsByTask(Project $project, TimeRange $range, ?string $search): array
    {
        $query = $this->baseQuery($project, $range)->whereNotNull('duration_ms');

        if ($search !== null && $search !== '') {
            $query->where('task', 'like', '%'.$search.'%');
        }

        $buckets = [];
        $query->orderBy('duration_ms')
            ->get(['task_hash', 'duration_ms'])
            ->each(function (ScheduledTaskRun $run) use (&$buckets) {
                $buckets[$run->task_hash][] = (int) $run->duration_ms;
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
