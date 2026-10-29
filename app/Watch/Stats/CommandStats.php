<?php

namespace App\Watch\Stats;

use App\Models\CommandRun;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class CommandStats
{
    /**
     * @return LengthAwarePaginator<array-key, array{command:string,successful:int,failed:int,total:int,avg_ms:float|null,p95_ms:float|null}>
     */
    public function paginatedCommands(
        Project $project,
        TimeRange $range,
        ?string $search,
        ?string $sort,
        ?string $dir,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        return StatsPaginator::paginate(
            items: $this->commands($project, $range, $search),
            sortable: [
                'command' => 'string',
                'successful' => 'numeric',
                'failed' => 'numeric',
                'total' => 'numeric',
                'avg_ms' => 'numeric',
                'p95_ms' => 'numeric',
            ],
            sort: $sort ?? 'command',
            dir: $dir ?? 'asc',
            page: $page,
            perPage: $perPage,
        );
    }

    /**
     * @return array{
     *   totals: array{total:int,successful:int,failed:int},
     *   duration: array{min_ms:int|null,max_ms:int|null,avg_ms:float|null,p95_ms:float|null,threshold_ms:int|null},
     *   buckets: list<array{bucket:string,successful:int,failed:int,avg_duration:float|null,p95_duration:float|null}>
     * }
     */
    public function summary(Project $project, TimeRange $range, ?string $command = null): array
    {
        $base = $this->baseQuery($project, $range, $command);

        $stats = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS successful")
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
                'successful' => (int) ($stats?->successful ?? 0),
                'failed' => (int) ($stats?->failed ?? 0),
            ],
            'duration' => [
                'min_ms' => $stats?->min_duration !== null ? (int) $stats->min_duration : null,
                'max_ms' => $stats?->max_duration !== null ? (int) $stats->max_duration : null,
                'avg_ms' => $stats?->avg_duration !== null ? (float) $stats->avg_duration : null,
                'p95_ms' => $this->percentile($durations, 0.95),
                'threshold_ms' => null,
            ],
            'buckets' => $this->buckets($project, $range, $command),
        ];
    }

    /**
     * @return list<array{command:string,successful:int,failed:int,total:int,avg_ms:float|null,p95_ms:float|null}>
     */
    public function commands(Project $project, TimeRange $range, ?string $search = null): array
    {
        $base = $this->baseQuery($project, $range);

        if ($search !== null && $search !== '') {
            $base->where('command', 'like', '%'.$search.'%');
        }

        $rows = (clone $base)
            ->selectRaw('command')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS successful")
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw('AVG(duration_ms) AS avg_ms')
            ->groupBy('command')
            ->get();

        $durationsByCommand = $this->durationsByCommand($project, $range, $search);

        return $rows
            ->map(function ($row) use ($durationsByCommand) {
                $durations = $durationsByCommand[$row->command] ?? [];

                return [
                    'command' => (string) $row->command,
                    'successful' => (int) $row->successful,
                    'failed' => (int) $row->failed,
                    'total' => (int) $row->total,
                    'avg_ms' => $row->avg_ms !== null ? (float) $row->avg_ms : null,
                    'p95_ms' => $this->percentile($durations, 0.95),
                ];
            })
            ->sortBy('command')
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   command:string,
     *   totals: array{total:int,successful:int,failed:int},
     *   duration: array{min_ms:int|null,max_ms:int|null,avg_ms:float|null,p95_ms:float|null,threshold_ms:int|null},
     *   buckets: list<array{bucket:string,successful:int,failed:int,avg_duration:float|null,p95_duration:float|null}>,
     *   calls: list<array{id:string,memory_peak_kb:int|null,command:string,exit_code:int|null,status:string,duration_ms:int|null,lazy_loads:int|null,hydrated_models:int|null,occurred_at:string|null}>
     * }|null
     */
    public function commandDetail(Project $project, TimeRange $range, string $command): ?array
    {
        $base = $this->baseQuery($project, $range, $command);

        if ((clone $base)->doesntExist()) {
            return null;
        }

        $summary = $this->summary($project, $range, $command);

        $calls = (clone $base)
            ->orderByDesc('occurred_at')
            ->limit(200)
            ->get(['id', 'memory_peak_kb', 'command', 'exit_code', 'status', 'duration_ms', 'lazy_loads', 'hydrated_models', 'occurred_at'])
            ->map(fn (CommandRun $run) => [
                'id' => $run->id,
                'memory_peak_kb' => $run->memory_peak_kb,
                'command' => $run->command,
                'exit_code' => $run->exit_code,
                'status' => $run->status,
                'duration_ms' => $run->duration_ms,
                'lazy_loads' => $run->lazy_loads,
                'hydrated_models' => $run->hydrated_models,
                'occurred_at' => $run->occurred_at?->toIso8601String(),
            ])
            ->all();

        return [
            'command' => $command,
            'totals' => $summary['totals'],
            'duration' => $summary['duration'],
            'buckets' => $summary['buckets'],
            'calls' => $calls,
        ];
    }

    /**
     * @return Builder<CommandRun>
     */
    private function baseQuery(Project $project, TimeRange $range, ?string $command = null): Builder
    {
        $query = CommandRun::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to]);

        if ($command !== null) {
            $query->where('command', $command);
        }

        return $query;
    }

    /**
     * @return list<array{bucket:string,successful:int,failed:int,avg_duration:float|null,p95_duration:float|null}>
     */
    private function buckets(Project $project, TimeRange $range, ?string $command = null): array
    {
        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));

        $rows = $this->baseQuery($project, $range, $command)
            ->orderBy('occurred_at')
            ->get(['status', 'duration_ms', 'occurred_at']);

        $start = $range->from->getTimestamp();
        $buckets = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $buckets[$i] = [
                'bucket' => CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String(),
                'successful' => 0,
                'failed' => 0,
                'durations' => [],
            ];
        }

        foreach ($rows as $row) {
            $offset = $row->occurred_at->getTimestamp() - $start;
            $idx = max(0, min($bucketCount - 1, intdiv($offset, $bucketSeconds)));

            if ($row->status === 'failed') {
                $buckets[$idx]['failed']++;
            } elseif ($row->status === 'completed') {
                $buckets[$idx]['successful']++;
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
                'successful' => $bucket['successful'],
                'failed' => $bucket['failed'],
                'avg_duration' => $durations === [] ? null : array_sum($durations) / count($durations),
                'p95_duration' => $this->percentile($durations, 0.95),
            ];
        }, $buckets);
    }

    /**
     * @return array<string, list<int>>
     */
    private function durationsByCommand(Project $project, TimeRange $range, ?string $search): array
    {
        $query = $this->baseQuery($project, $range)->whereNotNull('duration_ms');

        if ($search !== null && $search !== '') {
            $query->where('command', 'like', '%'.$search.'%');
        }

        $buckets = [];
        $query->orderBy('duration_ms')
            ->get(['command', 'duration_ms'])
            ->each(function (CommandRun $run) use (&$buckets) {
                $buckets[$run->command][] = (int) $run->duration_ms;
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
