<?php

namespace App\Watch\Stats;

use App\Models\LogEntry;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class LogStats
{
    public const LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical'];

    /**
     * @return LengthAwarePaginator<array-key, array{id:string,level:string,message:string,source_type:string|null,source_label:string|null,user_name:string|null,context:array<string,mixed>|null,occurred_at:string|null}>
     */
    public function paginated(
        Project $project,
        TimeRange $range,
        ?string $search,
        ?string $level,
        ?string $userName,
        int $page,
        int $perPage,
    ): LengthAwarePaginator {
        $query = $this->baseQuery($project, $range);

        if ($search !== null && $search !== '') {
            $query->searchMessage($search);
        }
        if ($level !== null && $level !== '' && in_array($level, self::LEVELS, true)) {
            $query->where('level', $level);
        }
        if ($userName !== null && $userName !== '') {
            $query->where('user_name', $userName);
        }

        $paginator = $query
            ->orderByDesc('occurred_at')
            ->paginate(perPage: $perPage, page: $page)
            ->withQueryString();

        return $paginator->through(fn (LogEntry $row) => [
            'id' => $row->id,
            'level' => $row->level,
            'message' => $row->message,
            'source_type' => $row->source_type,
            'source_label' => $row->source_label,
            'user_name' => $row->user_name,
            'context' => $row->context,
            'occurred_at' => $row->occurred_at?->toIso8601String(),
        ]);
    }

    /**
     * @return list<string>
     */
    public function users(Project $project, TimeRange $range): array
    {
        return $this->baseQuery($project, $range)
            ->whereNotNull('user_name')
            ->select('user_name')
            ->distinct()
            ->orderBy('user_name')
            ->pluck('user_name')
            ->map(fn ($v) => (string) $v)
            ->all();
    }

    /**
     * @return Builder<LogEntry>
     */
    private function baseQuery(Project $project, TimeRange $range): Builder
    {
        return LogEntry::query()
            ->where('project_id', $project->id)
            ->whereBetween('occurred_at', [$range->from, $range->to]);
    }
}
