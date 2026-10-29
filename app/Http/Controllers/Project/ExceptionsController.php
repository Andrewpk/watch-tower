<?php

namespace App\Http\Controllers\Project;

use App\Http\Controllers\Controller;
use App\Models\ErrorGroup;
use App\Models\ErrorOccurrence;
use App\Models\Project;
use App\Watch\Stats\ExceptionStats;
use App\Watch\Stats\TimeRange;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ExceptionsController extends Controller
{
    public function index(Project $project, Request $request, ExceptionStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '24h')->toString());
        $userId = $request->string('user_id')->toString() ?: null;
        $search = trim($request->string('search')->toString()) ?: null;
        $filter = $this->parseFilter($request->string('filter')->toString());

        $summary = $stats->summary($project, $range, $userId, $filter);
        $groups = $stats->groups($project, $range, $userId, $filter, $search);
        $users = $stats->topUsers($project, $range);

        return Inertia::render('projects/exceptions/index', [
            'summary' => $summary,
            'groups' => $groups,
            'users' => $users,
            'selectedRange' => $range->label,
            'filters' => [
                'filter' => $request->string('filter')->toString() ?: 'all',
                'user_id' => $userId,
                'search' => $search,
            ],
        ]);
    }

    public function show(Project $project, string $exception, Request $request, ExceptionStats $stats): Response
    {
        $range = TimeRange::fromLabel($request->string('range', '14d')->toString());
        $userId = $request->string('user_id')->toString() ?: null;

        /** @var ErrorGroup $group */
        $group = $project->errorGroups()
            ->where('id', $exception)
            ->firstOrFail();

        /** @var ErrorOccurrence|null $latest */
        $latest = $group->occurrences()
            ->with('trace:id,method,uri')
            ->latest('occurred_at')
            ->first();

        $detail = $stats->detail($project, $group);
        $buckets = $stats->buckets($project, $range, $userId, null, $group->id);
        $users = $stats->topUsers($project, $range);

        $totalsInRange = $group->occurrences()
            ->whereBetween('occurred_at', [$range->from, $range->to])
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN is_handled = 1 THEN 1 ELSE 0 END) AS handled')
            ->selectRaw('SUM(CASE WHEN is_handled = 0 THEN 1 ELSE 0 END) AS unhandled')
            ->first();

        $occurrenceList = $group->occurrences()
            ->with('trace:id,method,uri,is_stub')
            ->latest('occurred_at')
            ->limit(50)
            ->get()
            ->map(function (ErrorOccurrence $occurrence): array {
                $triggeredBy = $occurrence->triggeredBy();

                return [
                    'id' => $occurrence->id,
                    'occurred_at' => $occurrence->occurred_at?->toIso8601String(),
                    'message' => $occurrence->message,
                    'user_identifier' => $occurrence->user_identifier,
                    'user_email' => $occurrence->user_email,
                    'user_name' => $occurrence->user_name,
                    'source_type' => $triggeredBy['type'],
                    'source_label' => $triggeredBy['label'],
                ];
            })
            ->all();

        return Inertia::render('projects/exceptions/show', [
            'exception' => [
                'id' => $group->id,
                'exception_class' => $group->exception_class,
                'short_class' => $this->shortClass($group->exception_class),
                'first_message' => $group->first_message ?? '',
                'first_file' => $group->first_file,
                'first_line' => $group->first_line,
                'total_count' => (int) $group->total_count,
                'status' => $group->status,
                'is_handled' => (bool) $group->is_handled,
                'framework_version' => $group->framework_version,
                'language_version' => $group->language_version,
                'first_seen' => $detail['first_seen'],
                'last_seen' => $detail['last_seen'],
                'php_versions' => $detail['php_versions'],
                'laravel_versions' => $detail['laravel_versions'],
                'impacted_users' => $detail['impacted_users'],
                'servers' => $detail['servers'],
                'first_release' => $detail['first_release'],
                'environments' => $detail['environments'],
                'occurrences' => $detail['occurrences'],
                'totals_in_range' => [
                    'total' => (int) ($totalsInRange?->total ?? 0),
                    'handled' => (int) ($totalsInRange?->handled ?? 0),
                    'unhandled' => (int) ($totalsInRange?->unhandled ?? 0),
                ],
                'buckets' => $buckets,
                'latest_occurrence' => $latest ? [
                    'id' => $latest->id,
                    'message' => $latest->message,
                    'file' => $latest->file,
                    'line' => $latest->line,
                    'stacktrace' => $latest->stacktrace ?? [],
                    'context' => $latest->context ?? [],
                    'environment' => $latest->environment,
                    'occurred_at' => $latest->occurred_at?->toIso8601String(),
                ] : null,
                'occurrence_list' => $occurrenceList,
            ],
            'users' => $users,
            'selectedRange' => $range->label,
            'filters' => [
                'user_id' => $userId,
            ],
        ]);
    }

    private function parseFilter(string $filter): ?bool
    {
        return match ($filter) {
            'handled' => true,
            'unhandled' => false,
            default => null,
        };
    }

    private function shortClass(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);

        return $parts[count($parts) - 1] ?? $fqcn;
    }
}
