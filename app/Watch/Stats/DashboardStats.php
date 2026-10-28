<?php

namespace App\Watch\Stats;

use App\Models\ErrorOccurrence;
use App\Models\Project;
use App\Models\QueueJobRun;
use App\Models\Trace;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

class DashboardStats
{
    /**
     * @return array{
     *   range: array{from: string, to: string, label: string},
     *   activity: array<string, mixed>,
     *   exceptions: array<string, mixed>,
     *   jobs: array<string, mixed>
     * }
     */
    public function forProject(Project $project, TimeRange $range): array
    {
        return [
            'range' => [
                'from' => $range->from->toIso8601String(),
                'to' => $range->to->toIso8601String(),
                'label' => $range->label,
            ],
            'activity' => $this->activity($project, $range),
            'exceptions' => $this->exceptions($project, $range),
            'jobs' => $this->jobs($project, $range),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function activity(Project $project, TimeRange $range): array
    {
        $traces = Trace::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->orderBy('occurred_at');

        $stats = (clone $traces)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN status_code < 400 THEN 1 ELSE 0 END) AS success')
            ->selectRaw('SUM(CASE WHEN status_code >= 400 AND status_code < 500 THEN 1 ELSE 0 END) AS client_error')
            ->selectRaw('SUM(CASE WHEN status_code >= 500 THEN 1 ELSE 0 END) AS server_error')
            ->selectRaw('MIN(duration_ms) AS min_duration')
            ->selectRaw('MAX(duration_ms) AS max_duration')
            ->selectRaw('AVG(duration_ms) AS avg_duration')
            ->first();

        $durations = (clone $traces)
            ->whereNotNull('duration_ms')
            ->orderBy('duration_ms')
            ->pluck('duration_ms')
            ->all();

        $buckets = $this->bucketTraces($project, $range);

        return [
            'requests' => [
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
                'p99_ms' => $this->percentile($durations, 0.99),
            ],
            'buckets' => $buckets,
        ];
    }

    /**
     * @return list<array{bucket: string, success: int, client_error: int, server_error: int, avg_duration: float|null, p95_duration: float|null}>
     */
    private function bucketTraces(Project $project, TimeRange $range): array
    {
        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));

        $rows = Trace::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to])
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
            /** @var CarbonInterface $occurredAt */
            $occurredAt = $row->occurred_at;
            $offset = $occurredAt->getTimestamp() - $start;
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
     * @return array<string, mixed>
     */
    private function exceptions(Project $project, TimeRange $range): array
    {
        $count = ErrorOccurrence::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->count();

        return [
            'total' => $count,
            'window_label' => $range->humanLabel(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobs(Project $project, TimeRange $range): array
    {
        $jobs = QueueJobRun::query()
            ->where('project_id', $project->id)
            ->whereBetween('created_at', [$range->from, $range->to]);

        $stats = (clone $jobs)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS processed")
            ->selectRaw("SUM(CASE WHEN status = 'released' THEN 1 ELSE 0 END) AS released")
            ->selectRaw('MIN(duration_ms) AS min_duration')
            ->selectRaw('MAX(duration_ms) AS max_duration')
            ->selectRaw('AVG(duration_ms) AS avg_duration')
            ->first();

        $durations = (clone $jobs)
            ->whereNotNull('duration_ms')
            ->pluck('duration_ms')
            ->all();
        sort($durations);

        return [
            'total' => (int) ($stats?->total ?? 0),
            'failed' => (int) ($stats?->failed ?? 0),
            'processed' => (int) ($stats?->processed ?? 0),
            'released' => (int) ($stats?->released ?? 0),
            'duration' => [
                'min_ms' => $stats?->min_duration !== null ? (int) $stats->min_duration : null,
                'max_ms' => $stats?->max_duration !== null ? (int) $stats->max_duration : null,
                'avg_ms' => $stats?->avg_duration !== null ? (float) $stats->avg_duration : null,
                'p95_ms' => $this->percentile($durations, 0.95),
            ],
            'buckets' => $this->bucketJobs($project, $range),
        ];
    }

    /**
     * @return list<array{bucket: string, total: int, failed: int}>
     */
    private function bucketJobs(Project $project, TimeRange $range): array
    {
        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));

        $rows = QueueJobRun::query()
            ->where('project_id', $project->id)
            ->whereBetween('created_at', [$range->from, $range->to])
            ->orderBy('created_at')
            ->get(['status', 'created_at']);

        $start = $range->from->getTimestamp();
        $buckets = [];

        for ($i = 0; $i < $bucketCount; $i++) {
            $buckets[$i] = [
                'bucket' => CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String(),
                'total' => 0,
                'failed' => 0,
            ];
        }

        foreach ($rows as $row) {
            $createdAt = $row->created_at;
            $offset = $createdAt->getTimestamp() - $start;
            $idx = max(0, min($bucketCount - 1, intdiv($offset, $bucketSeconds)));

            $buckets[$idx]['total']++;
            if ($row->status === 'failed') {
                $buckets[$idx]['failed']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @param  list<int|float>  $sortedOrUnsorted
     */
    private function percentile(array $sortedOrUnsorted, float $p): ?float
    {
        if ($sortedOrUnsorted === []) {
            return null;
        }
        $values = $sortedOrUnsorted;
        sort($values);

        $index = (int) floor(($p) * (count($values) - 1));

        return (float) $values[$index];
    }
}
