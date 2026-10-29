import { cn } from '@/lib/utils';

export type StageKey =
    | 'bootstrap'
    | 'before_middleware'
    | 'action'
    | 'render'
    | 'after_middleware'
    | 'sending'
    | 'terminating';

export type RequestStages = Record<StageKey, number | null>;

export const REQUEST_STAGES: Array<{
    key: StageKey;
    label: string;
    className: string;
}> = [
    {
        key: 'bootstrap',
        label: 'Bootstrap',
        className: 'bg-slate-400 dark:bg-slate-500',
    },
    {
        key: 'before_middleware',
        label: 'Before middleware',
        className: 'bg-sky-500 dark:bg-sky-400',
    },
    {
        key: 'action',
        label: 'Action',
        className: 'bg-indigo-500 dark:bg-indigo-400',
    },
    {
        key: 'render',
        label: 'Render',
        className: 'bg-violet-500 dark:bg-violet-400',
    },
    {
        key: 'after_middleware',
        label: 'After middleware',
        className: 'bg-emerald-500 dark:bg-emerald-400',
    },
    {
        key: 'sending',
        label: 'Sending',
        className: 'bg-amber-500 dark:bg-amber-400',
    },
    {
        key: 'terminating',
        label: 'Terminating',
        className: 'bg-rose-500 dark:bg-rose-400',
    },
];

/**
 * Stage durations are fractional milliseconds: most stages of a fast request take well under one.
 */
export function formatStageMs(value: number): string {
    // Round first, so 999.6 reads "1.00 s" and 99.96 reads "100 ms".
    if (Math.round(value) >= 1000) {
        return `${(value / 1000).toFixed(2)} s`;
    }

    if (Number(value.toFixed(1)) >= 100) {
        return `${Math.round(value)} ms`;
    }

    if (Number(value.toFixed(2)) >= 10) {
        return `${value.toFixed(1)} ms`;
    }

    return `${value.toFixed(2)} ms`;
}

function visibleStages(stages: RequestStages) {
    return REQUEST_STAGES.map((stage) => ({
        ...stage,
        value: stages[stage.key],
    })).filter(
        (stage): stage is typeof stage & { value: number } =>
            stage.value !== null && stage.value > 0,
    );
}

/**
 * Stacked bar of the stage durations with a legend listing each stage in milliseconds. Renders nothing when no
 * stage was reported.
 */
export function StageBreakdown({
    stages,
    className,
}: {
    stages: RequestStages;
    className?: string;
}) {
    const visible = visibleStages(stages);

    if (visible.length === 0) {
        return null;
    }

    const total = visible.reduce((sum, stage) => sum + stage.value, 0);

    return (
        <div className={cn('space-y-3', className)}>
            <div
                aria-hidden="true"
                className="flex h-3 w-full overflow-hidden rounded-full bg-muted"
            >
                {visible.map((stage) => (
                    <div
                        key={stage.key}
                        className={stage.className}
                        style={{ width: `${(stage.value / total) * 100}%` }}
                        title={`${stage.label}: ${formatStageMs(stage.value)}`}
                    />
                ))}
            </div>
            <dl className="grid grid-cols-2 gap-x-6 gap-y-1.5 text-xs">
                {REQUEST_STAGES.map((stage) => {
                    const value = stages[stage.key];

                    return (
                        <div
                            key={stage.key}
                            className="flex items-center justify-between gap-2"
                        >
                            <dt className="flex items-center gap-2 text-muted-foreground">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'h-2 w-2 rounded-full',
                                        stage.className,
                                    )}
                                />
                                {stage.label}
                            </dt>
                            <dd className="font-mono">
                                {value === null ? '—' : formatStageMs(value)}
                            </dd>
                        </div>
                    );
                })}
            </dl>
        </div>
    );
}

/**
 * Compact stacked bar for table cells. Renders nothing when no stage was reported.
 */
export function StageBar({
    stages,
    className,
}: {
    stages: RequestStages;
    className?: string;
}) {
    const visible = visibleStages(stages);

    if (visible.length === 0) {
        return null;
    }

    const total = visible.reduce((sum, stage) => sum + stage.value, 0);

    return (
        <div
            role="img"
            aria-label={visible
                .map((stage) => `${stage.label} ${formatStageMs(stage.value)}`)
                .join(', ')}
            className={cn(
                'flex h-1.5 w-full overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            {visible.map((stage) => (
                <div
                    key={stage.key}
                    className={stage.className}
                    style={{ width: `${(stage.value / total) * 100}%` }}
                    title={`${stage.label}: ${formatStageMs(stage.value)}`}
                />
            ))}
        </div>
    );
}
