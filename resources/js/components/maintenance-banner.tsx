import { usePage } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';

import { formatRelativeTime } from '@/lib/utils';
import type { MaintenanceProblem, SharedProps } from '@/types/inertia';

function describe(problem: MaintenanceProblem): string {
    if (problem.key === 'prune_overdue') {
        const when = problem.last_run_at
            ? `last ran ${formatRelativeTime(problem.last_run_at)}`
            : 'has not run since this version was installed';

        return `Retention pruning ${when}. Old telemetry is not being deleted, so the database keeps growing until the disk is full. Make sure the scheduler is running (see the Docker guide), or run \`php artisan watch:prune\`.`;
    }

    return `Ingested events have been waiting in the queue since ${new Date(problem.waiting_since).toLocaleString()}. No queue worker seems to be running, so new telemetry is not stored.`;
}

/**
 * Warns super admins that the scheduler or the queue worker has stopped.
 * The server only sends problems to super admins, so this renders nothing for
 * everyone else.
 */
export function MaintenanceBanner() {
    const { maintenanceProblems } = usePage<SharedProps>().props;

    if (!maintenanceProblems?.length) {
        return null;
    }

    return (
        <div className="space-y-2 px-6 pt-4">
            {maintenanceProblems.map((problem) => (
                <div
                    key={problem.key}
                    role="status"
                    className="flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300"
                >
                    <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                    <p>{describe(problem)}</p>
                </div>
            ))}
        </div>
    );
}
