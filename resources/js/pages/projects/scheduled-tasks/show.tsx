import { Link } from '@inertiajs/react';
import { ExternalLink } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Scatter,
    ScatterChart,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { ModelCounts } from '@/components/model-counts';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { AppLayout } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import scheduledTasksRoutes from '@/routes/projects/scheduled-tasks';

type Bucket = {
    bucket: string;
    processed: number;
    skipped: number;
    failed: number;
    avg_duration: number | null;
    p95_duration: number | null;
};

type Run = {
    id: string;
    status: string;
    duration_ms: number | null;
    exit_code: number | null;
    lazy_loads: number | null;
    hydrated_models: number | null;
    occurred_at: string | null;
};

type Detail = {
    task: string;
    task_hash: string;
    schedule: string | null;
    schedule_summary: string | null;
    next_run_at: string | null;
    totals: {
        total: number;
        processed: number;
        skipped: number;
        failed: number;
    };
    duration: {
        min_ms: number | null;
        max_ms: number | null;
        avg_ms: number | null;
        p95_ms: number | null;
        threshold_ms: number | null;
    };
    buckets: Bucket[];
    runs: Run[];
};

type StatusFilter = 'all' | 'processed' | 'skipped' | 'failed';
type DurationFilter = 'all' | 'avg' | 'p95';

type Props = {
    detail: Detail;
    selectedRange: string;
};

export default function ScheduledTasksShow({ detail, selectedRange }: Props) {
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const [durationFilter, setDurationFilter] = useState<DurationFilter>('all');

    const filteredRuns = useMemo(() => {
        return detail.runs.filter((r) => {
            if (statusFilter !== 'all' && r.status !== statusFilter) {
                return false;
            }

            if (
                durationFilter === 'avg' &&
                detail.duration.avg_ms !== null &&
                r.duration_ms !== null
            ) {
                if (r.duration_ms < detail.duration.avg_ms) {
                    return false;
                }
            }

            if (
                durationFilter === 'p95' &&
                detail.duration.p95_ms !== null &&
                r.duration_ms !== null
            ) {
                if (r.duration_ms < detail.duration.p95_ms) {
                    return false;
                }
            }

            return true;
        });
    }, [
        detail.runs,
        detail.duration.avg_ms,
        detail.duration.p95_ms,
        statusFilter,
        durationFilter,
    ]);

    const slug =
        typeof window !== 'undefined'
            ? window.location.pathname.split('/')[2]
            : '';

    return (
        <AppLayout title={detail.task}>
            <PageHeader
                title={
                    <div className="space-y-0.5">
                        <div className="text-[11px] font-normal tracking-wider text-muted-foreground uppercase">
                            <Link
                                href={scheduledTasksRoutes.index(slug).url}
                                className="hover:text-foreground"
                            >
                                Scheduled Tasks
                            </Link>
                        </div>
                        <div className="flex items-center gap-3 font-mono">
                            <span>{detail.task}</span>
                            {(detail.schedule_summary ?? detail.schedule) ? (
                                <span className="rounded-md border border-border bg-muted px-2 py-0.5 font-mono text-[11px] text-muted-foreground">
                                    {detail.schedule_summary ?? detail.schedule}
                                </span>
                            ) : null}
                        </div>
                    </div>
                }
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <RunsCard detail={detail} />
                    <DurationCard detail={detail} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 space-y-0 pb-3">
                        <CardTitle>{filteredRuns.length} Runs</CardTitle>
                        <div className="flex items-center gap-3">
                            <Tabs
                                value={durationFilter}
                                onValueChange={(v) =>
                                    setDurationFilter(v as DurationFilter)
                                }
                            >
                                <TabsList className="h-8">
                                    <TabsTrigger value="all" className="px-3">
                                        View all
                                    </TabsTrigger>
                                    <TabsTrigger value="avg" className="px-3">
                                        ≥ AVG
                                    </TabsTrigger>
                                    <TabsTrigger value="p95" className="px-3">
                                        ≥ P95
                                    </TabsTrigger>
                                </TabsList>
                            </Tabs>
                            <Tabs
                                value={statusFilter}
                                onValueChange={(v) =>
                                    setStatusFilter(v as StatusFilter)
                                }
                            >
                                <TabsList className="h-8">
                                    <TabsTrigger value="all" className="px-3">
                                        View all
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="skipped"
                                        className="gap-1.5 px-3"
                                    >
                                        Skipped
                                        {detail.totals.skipped > 0 ? (
                                            <Badge
                                                variant="muted"
                                                className="px-1.5 text-[10px]"
                                            >
                                                {detail.totals.skipped}
                                            </Badge>
                                        ) : null}
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="failed"
                                        className="gap-1.5 px-3"
                                    >
                                        Failed
                                        {detail.totals.failed > 0 ? (
                                            <Badge
                                                variant="muted"
                                                className="px-1.5 text-[10px]"
                                            >
                                                {detail.totals.failed}
                                            </Badge>
                                        ) : null}
                                    </TabsTrigger>
                                </TabsList>
                            </Tabs>
                        </div>
                    </CardHeader>
                    <Separator />
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead className="w-72">DATE</TableHead>
                                    <TableHead>STATUS</TableHead>
                                    <TableHead className="text-right">
                                        DURATION
                                    </TableHead>
                                    <TableHead className="w-32 text-right">
                                        LAZY / MODELS
                                    </TableHead>
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredRuns.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No runs match the current filters
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    filteredRuns.map((run) => (
                                        <TableRow key={run.id}>
                                            <TableCell className="py-2.5 font-mono text-xs text-muted-foreground">
                                                {formatUtc(run.occurred_at)}
                                            </TableCell>
                                            <TableCell className="py-2.5">
                                                <ScheduledTaskStatusBadge
                                                    status={run.status}
                                                />
                                            </TableCell>
                                            <TableCell className="py-2.5 text-right font-mono text-xs">
                                                {formatMs(run.duration_ms)}
                                            </TableCell>
                                            <TableCell className="py-2.5 text-right font-mono text-xs">
                                                <ModelCounts
                                                    lazyLoads={run.lazy_loads}
                                                    hydratedModels={
                                                        run.hydrated_models
                                                    }
                                                />
                                            </TableCell>
                                            <TableCell className="py-2.5 text-right">
                                                <button
                                                    type="button"
                                                    className="inline-flex text-muted-foreground hover:text-foreground"
                                                >
                                                    <ExternalLink className="h-3.5 w-3.5" />
                                                </button>
                                            </TableCell>
                                        </TableRow>
                                    ))
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function RunsCard({ detail }: { detail: Detail }) {
    const { totals, buckets } = detail;
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        Processed: b.processed,
        Skipped: b.skipped,
        Failed: b.failed,
    }));

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Runs
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-baseline gap-6">
                    <div>
                        <div className="text-3xl font-semibold">
                            {formatNumber(totals.total)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Runs
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="Failed"
                        value={formatNumber(totals.failed)}
                        accent="text-rose-600 dark:text-rose-400"
                        dot="bg-rose-500"
                    />
                    <Stat
                        label="Processed"
                        value={formatNumber(totals.processed)}
                        accent="text-emerald-600 dark:text-emerald-400"
                        dot="bg-emerald-500"
                    />
                    <Stat
                        label="Skipped"
                        value={formatNumber(totals.skipped)}
                        accent="text-amber-600 dark:text-amber-400"
                        dot="bg-amber-500"
                    />
                </div>

                <div className="mt-5 h-40">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={data}>
                            <CartesianGrid
                                stroke="var(--color-border)"
                                strokeDasharray="3 3"
                                vertical={false}
                            />
                            <XAxis
                                dataKey="time"
                                tick={{
                                    fontSize: 10,
                                    fill: 'var(--color-muted-foreground)',
                                }}
                                interval={Math.max(
                                    0,
                                    Math.floor(data.length / 6),
                                )}
                            />
                            <YAxis
                                tick={{
                                    fontSize: 10,
                                    fill: 'var(--color-muted-foreground)',
                                }}
                                width={28}
                            />
                            <Tooltip
                                cursor={{ fill: 'var(--color-muted)' }}
                                contentStyle={tooltipStyle}
                            />
                            <Bar
                                dataKey="Processed"
                                stackId="s"
                                fill="#10b981"
                            />
                            <Bar dataKey="Skipped" stackId="s" fill="#f59e0b" />
                            <Bar
                                dataKey="Failed"
                                stackId="s"
                                fill="#ef4444"
                                radius={[2, 2, 0, 0]}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </div>

                <div className="mt-3 flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>
                        {buckets[0] ? formatUtc(buckets[0].bucket) : ''}
                    </span>
                    <span>
                        {buckets[buckets.length - 1]
                            ? formatUtc(buckets[buckets.length - 1].bucket)
                            : ''}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function DurationCard({ detail }: { detail: Detail }) {
    const { duration, buckets } = detail;
    const avgPoints = buckets.flatMap((b, i) =>
        b.avg_duration === null
            ? []
            : [{ x: i, y: b.avg_duration, time: b.bucket }],
    );
    const p95Points = buckets.flatMap((b, i) =>
        b.p95_duration === null
            ? []
            : [{ x: i, y: b.p95_duration, time: b.bucket }],
    );

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Duration
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-baseline gap-6">
                    <div>
                        <div className="text-3xl font-semibold">
                            {formatMs(duration.min_ms)}
                            <span className="px-2 text-base text-muted-foreground">
                                –
                            </span>
                            {formatMs(duration.max_ms)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Min – Max
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="Threshold"
                        value={
                            duration.threshold_ms !== null
                                ? formatMs(duration.threshold_ms)
                                : 'N/A'
                        }
                        accent="text-foreground"
                        dot="bg-sky-500"
                    />
                    <Stat
                        label="Avg"
                        value={formatMs(duration.avg_ms)}
                        accent="text-foreground"
                        dot="bg-muted-foreground"
                    />
                    <Stat
                        label="P95"
                        value={formatMs(duration.p95_ms)}
                        accent="text-amber-600 dark:text-amber-400"
                        dot="bg-amber-500"
                    />
                </div>

                <div className="mt-5 h-40">
                    <ResponsiveContainer width="100%" height="100%">
                        <ScatterChart>
                            <CartesianGrid
                                stroke="var(--color-border)"
                                strokeDasharray="3 3"
                                vertical={false}
                            />
                            <XAxis
                                dataKey="x"
                                type="number"
                                domain={[0, buckets.length - 1]}
                                tick={false}
                                axisLine={false}
                            />
                            <YAxis
                                dataKey="y"
                                type="number"
                                tick={{
                                    fontSize: 10,
                                    fill: 'var(--color-muted-foreground)',
                                }}
                                width={32}
                                unit="ms"
                            />
                            <Tooltip
                                contentStyle={tooltipStyle}
                                formatter={(value) =>
                                    `${Number(value).toFixed(0)} ms`
                                }
                                cursor={{ strokeDasharray: '3 3' }}
                            />
                            <Scatter
                                name="Avg"
                                data={avgPoints}
                                fill="#9ca3af"
                            />
                            <Scatter
                                name="P95"
                                data={p95Points}
                                fill="#f59e0b"
                            />
                        </ScatterChart>
                    </ResponsiveContainer>
                </div>

                <div className="mt-3 flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>
                        {buckets[0] ? formatUtc(buckets[0].bucket) : ''}
                    </span>
                    <span>
                        {buckets[buckets.length - 1]
                            ? formatUtc(buckets[buckets.length - 1].bucket)
                            : ''}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function Stat({
    label,
    value,
    accent,
    dot,
}: {
    label: string;
    value: string;
    accent: string;
    dot?: string;
}) {
    return (
        <div>
            <div className="flex items-center gap-1.5 text-[10px] tracking-wider text-muted-foreground uppercase">
                {dot ? (
                    <span className={cn('h-1.5 w-1.5 rounded-full', dot)} />
                ) : null}
                {label}
            </div>
            <div className={cn('text-base font-semibold', accent)}>{value}</div>
        </div>
    );
}

function ScheduledTaskStatusBadge({ status }: { status: string }) {
    const map: Record<string, { label: string; cls: string }> = {
        processed: {
            label: 'PROCESSED',
            cls: 'border-emerald-500/40 text-emerald-600 dark:text-emerald-400',
        },
        skipped: {
            label: 'SKIPPED',
            cls: 'border-amber-500/40 text-amber-600 dark:text-amber-400',
        },
        failed: {
            label: 'FAILED',
            cls: 'border-rose-500/40 text-rose-600 dark:text-rose-400',
        },
    };
    const info = map[status] ?? {
        label: status.toUpperCase(),
        cls: 'border-border text-muted-foreground',
    };

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-[10px] font-semibold',
                info.cls,
            )}
        >
            {info.label}
        </span>
    );
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function formatMs(value: number | null): string {
    if (value === null || Number.isNaN(value)) {
        return '—';
    }

    if (value >= 1000) {
        return `${(value / 1000).toFixed(2)} s`;
    }

    return `${Math.round(value)} ms`;
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatUtc(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const d = new Date(iso);
    const pad = (n: number) => String(n).padStart(2, '0');

    return `${d.getUTCFullYear()}-${pad(d.getUTCMonth() + 1)}-${pad(d.getUTCDate())} ${pad(d.getUTCHours())}:${pad(d.getUTCMinutes())}:${pad(d.getUTCSeconds())} UTC`;
}

const tooltipStyle = {
    background: 'var(--color-popover)',
    border: '1px solid var(--color-border)',
    borderRadius: 6,
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
} as const;
