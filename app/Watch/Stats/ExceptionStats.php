<?php

namespace App\Watch\Stats;

use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\Project;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class ExceptionStats
{
    /**
     * @return array{
     *   totals: array{total:int, handled:int, unhandled:int},
     *   buckets: list<array{bucket:string, handled:int, unhandled:int}>
     * }
     */
    public function summary(Project $project, TimeRange $range, ?string $userId = null, ?bool $handled = null): array
    {
        $base = $this->baseQuery($project, $range, $userId, $handled);

        $totals = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN is_handled = 1 THEN 1 ELSE 0 END) AS handled')
            ->selectRaw('SUM(CASE WHEN is_handled = 0 THEN 1 ELSE 0 END) AS unhandled')
            ->first();

        return [
            'totals' => [
                'total' => (int) ($totals?->total ?? 0),
                'handled' => (int) ($totals?->handled ?? 0),
                'unhandled' => (int) ($totals?->unhandled ?? 0),
            ],
            'buckets' => $this->buckets($project, $range, $userId, $handled),
        ];
    }

    /**
     * @return list<array{
     *   id:string,
     *   exception_class:string,
     *   short_class:string,
     *   first_message:string,
     *   last_occurrence_at:string|null,
     *   total_count:int,
     *   users_count:int,
     *   is_handled:bool
     * }>
     */
    public function groups(Project $project, TimeRange $range, ?string $userId = null, ?bool $handled = null, ?string $search = null): array
    {
        $occurrenceCounts = ErrorOccurrence::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->when($userId !== null && $userId !== '', fn (Builder $q) => $q->where('user_identifier', $userId))
            ->when($handled !== null, fn (Builder $q) => $q->where('is_handled', $handled))
            ->selectRaw('error_group_id')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(DISTINCT user_identifier) AS users_count')
            ->selectRaw('MAX(occurred_at) AS last_occurrence_at')
            ->whereNotNull('error_group_id')
            ->groupBy('error_group_id')
            ->get()
            ->keyBy('error_group_id');

        if ($occurrenceCounts->isEmpty()) {
            return [];
        }

        $query = ErrorGroup::query()
            ->where('project_id', $project->id)
            ->whereIn('id', $occurrenceCounts->keys());

        if ($search !== null && $search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('exception_class', 'like', $like)
                    ->orWhere('first_message', 'like', $like);
            });
        }

        $groups = $query->get();

        return $groups
            ->map(function (ErrorGroup $group) use ($occurrenceCounts, $handled) {
                $stats = $occurrenceCounts->get($group->id);

                return [
                    'id' => $group->id,
                    'display_number' => $group->display_number,
                    'exception_class' => $group->exception_class,
                    'short_class' => $this->shortClass($group->exception_class),
                    'first_message' => $group->first_message ?? '',
                    'last_occurrence_at' => $stats?->last_occurrence_at
                        ? CarbonImmutable::parse($stats->last_occurrence_at)->toIso8601String()
                        : null,
                    'total_count' => (int) ($stats?->total ?? 0),
                    'users_count' => (int) ($stats?->users_count ?? 0),
                    'is_handled' => $handled ?? (bool) $group->is_handled,
                ];
            })
            ->sortByDesc('last_occurrence_at')
            ->values()
            ->all();
    }

    /**
     * `first_release` is the release of the oldest retained occurrence that has one; older occurrences may already
     * have been pruned.
     *
     * @return array{
     *   last_seen: string|null,
     *   first_seen: string|null,
     *   php_versions: list<string>,
     *   laravel_versions: list<string>,
     *   impacted_users: int,
     *   servers: list<string>,
     *   first_release: string|null,
     *   environments: list<array{environment:string,count:int}>,
     *   occurrences: array{day:int, week:int, month:int}
     * }
     */
    public function detail(Project $project, ErrorGroup $group): array
    {
        $now = CarbonImmutable::now();

        $base = ErrorOccurrence::query()
            ->where('error_occurrences.project_id', $project->id)
            ->where('error_occurrences.error_group_id', $group->id);

        $impactedUsers = (int) (clone $base)
            ->whereNotNull('user_identifier')
            ->distinct('user_identifier')
            ->count('user_identifier');

        $environments = (clone $base)
            ->selectRaw('environment, COUNT(*) AS total')
            ->groupBy('environment')
            ->get()
            ->map(fn ($row) => [
                'environment' => $row->environment ?? 'unknown',
                'count' => (int) $row->total,
            ])
            ->all();

        $traceServers = (clone $base)
            ->join('traces', 'error_occurrences.trace_id', '=', 'traces.id')
            ->whereNotNull('traces.hostname')
            ->distinct()
            ->pluck('traces.hostname');

        $occurrenceServers = (clone $base)
            ->whereNotNull('error_occurrences.hostname')
            ->distinct()
            ->pluck('error_occurrences.hostname');

        $servers = $occurrenceServers
            ->merge($traceServers)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $phpVersions = $this->recentVersions($base, 'language_version', $group->language_version);
        $laravelVersions = $this->recentVersions($base, 'framework_version', $group->framework_version);

        $firstRelease = (clone $base)
            ->whereNotNull('release_version')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->value('release_version');

        $first = $group->first_occurrence_at?->toIso8601String();
        $last = $group->last_occurrence_at?->toIso8601String();

        $day = (int) (clone $base)->where('occurred_at', '>=', $now->subDay())->count();
        $week = (int) (clone $base)->where('occurred_at', '>=', $now->subDays(7))->count();
        $month = (int) (clone $base)->where('occurred_at', '>=', $now->subDays(30))->count();

        return [
            'last_seen' => $last,
            'first_seen' => $first,
            'php_versions' => $phpVersions,
            'laravel_versions' => $laravelVersions,
            'impacted_users' => $impactedUsers,
            'servers' => $servers,
            'first_release' => $firstRelease,
            'environments' => $environments,
            'occurrences' => [
                'day' => $day,
                'week' => $week,
                'month' => $month,
            ],
        ];
    }

    /**
     * Distinct non-null values of a version column, the most recently seen first (at most ten). Falls back to the
     * group's own value when no occurrence carries one, e.g. for occurrences stored before the column existed.
     *
     * @param  Builder<ErrorOccurrence>  $base
     * @return list<string>
     */
    private function recentVersions(Builder $base, string $column, ?string $groupValue): array
    {
        $versions = (clone $base)
            ->whereNotNull($column)
            ->groupBy($column)
            ->selectRaw("{$column} AS version, MAX(occurred_at) AS last_seen")
            ->orderByDesc('last_seen')
            ->limit(10)
            ->pluck('version')
            ->all();

        if ($versions === [] && $groupValue) {
            return [$groupValue];
        }

        return $versions;
    }

    /**
     * @return Builder<ErrorOccurrence>
     */
    private function baseQuery(Project $project, TimeRange $range, ?string $userId, ?bool $handled, ?string $groupId = null): Builder
    {
        $query = ErrorOccurrence::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to]);

        if ($userId !== null && $userId !== '') {
            $query->where('user_identifier', $userId);
        }

        if ($handled !== null) {
            $query->where('is_handled', $handled);
        }

        if ($groupId !== null) {
            $query->where('error_group_id', $groupId);
        }

        return $query;
    }

    /**
     * @return list<array{bucket:string, handled:int, unhandled:int}>
     */
    public function buckets(Project $project, TimeRange $range, ?string $userId = null, ?bool $handled = null, ?string $groupId = null): array
    {
        $bucketCount = 60;
        $totalSeconds = max(1, $range->from->diffInSeconds($range->to));
        $bucketSeconds = max(1, intdiv($totalSeconds, $bucketCount));

        $rows = $this->baseQuery($project, $range, $userId, $handled, $groupId)
            ->orderBy('occurred_at')
            ->get(['is_handled', 'occurred_at']);

        $start = $range->from->getTimestamp();
        $buckets = [];
        for ($i = 0; $i < $bucketCount; $i++) {
            $buckets[$i] = [
                'bucket' => CarbonImmutable::createFromTimestamp($start + $i * $bucketSeconds)->toIso8601String(),
                'handled' => 0,
                'unhandled' => 0,
            ];
        }

        foreach ($rows as $row) {
            $offset = $row->occurred_at->getTimestamp() - $start;
            $idx = max(0, min($bucketCount - 1, intdiv($offset, $bucketSeconds)));

            if ($row->is_handled) {
                $buckets[$idx]['handled']++;
            } else {
                $buckets[$idx]['unhandled']++;
            }
        }

        return array_values($buckets);
    }

    /**
     * @return list<array{id:string,email:string|null,count:int}>
     */
    public function topUsers(Project $project, TimeRange $range, int $limit = 30): array
    {
        return ErrorOccurrence::query()
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

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return $parts[count($parts) - 1] ?? $fqcn;
    }
}
