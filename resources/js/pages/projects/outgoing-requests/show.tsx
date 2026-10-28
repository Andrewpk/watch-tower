import { usePage } from '@inertiajs/react';
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
    ZAxis,
} from 'recharts';

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
import httpClientRoutes from '@/routes/projects/http-client';
import type { SharedProps } from '@/types/inertia';

type Bucket = {
    bucket: string;
    count: number;
    avg_duration: number | null;
    p95_duration: number | null;
    '1xx': number;
    '2xx': number;
    '3xx': number;
    '4xx': number;
    '5xx': number;
    failed: number;
};

type OutgoingRequestRow = {
    id: string;
    method: string;
    url: string;
    status_code: number | null;
    duration_ms: number | null;
    source_type: string | null;
    source_label: string | null;
    occurred_at: string | null;
};

type Detail = {
    host: string;
    hash: string;
    totals: {
        total: number;
        total_ms: number;
        min_ms: number | null;
        max_ms: number | null;
        avg_ms: number | null;
        p95_ms: number | null;
        status: {
            '1xx': number;
            '2xx': number;
            '3xx': number;
            '4xx': number;
            '5xx': number;
            failed: number;
        };
    };
    buckets: Bucket[];
    requests: OutgoingRequestRow[];
};

type Props = {
    detail: Detail;
    selectedRange: string;
};

type DurationFilter = 'all' | 'avg' | 'p95';
type StatusFilter = 'all' | '1xx' | '2xx' | '3xx' | '4xx' | '5xx' | 'failed';

export default function OutgoingRequestsShow({ detail, selectedRange }: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    const [durationFilter, setDurationFilter] = useState<DurationFilter>('all');
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');

    const filtered = useMemo(() => {
        let rows = detail.requests;

        if (durationFilter === 'avg' && detail.totals.avg_ms !== null) {
            rows = rows.filter(
                (r) => (r.duration_ms ?? 0) >= (detail.totals.avg_ms ?? 0),
            );
        }

        if (durationFilter === 'p95' && detail.totals.p95_ms !== null) {
            rows = rows.filter(
                (r) => (r.duration_ms ?? 0) >= (detail.totals.p95_ms ?? 0),
            );
        }

        if (statusFilter !== 'all') {
            rows = rows.filter(
                (r) => statusBucket(r.status_code) === statusFilter,
            );
        }

        return rows;
    }, [
        detail.requests,
        detail.totals.avg_ms,
        detail.totals.p95_ms,
        durationFilter,
        statusFilter,
    ]);

    return (
        <AppLayout title="Outgoing requests detail">
            <PageHeader
                title={
                    <span className="flex flex-col gap-1">
                        <span className="text-xs font-normal tracking-wider text-muted-foreground uppercase">
                            Domain
                        </span>
                        <span className="block max-w-[920px] font-mono text-base leading-snug font-medium break-words">
                            {detail.host}
                        </span>
                    </span>
                }
                breadcrumbs={[
                    { label: 'Activity' },
                    {
                        label: 'HTTP Client',
                        href: httpClientRoutes.index(slug).url,
                    },
                    { label: 'Detail' },
                ]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <RequestsCard detail={detail} />
                    <DurationCard detail={detail} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle>
                            {detail.requests.length.toLocaleString()} Requests
                        </CardTitle>
                        <div className="flex items-center gap-3">
                            <Tabs
                                value={statusFilter}
                                onValueChange={(v) =>
                                    setStatusFilter(v as StatusFilter)
                                }
                            >
                                <TabsList className="h-8">
                                    <TabsTrigger
                                        value="all"
                                        className="text-[11px]"
                                    >
                                        All
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="1xx"
                                        className="text-[11px]"
                                        disabled={
                                            detail.totals.status['1xx'] === 0
                                        }
                                    >
                                        1XX
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="2xx"
                                        className="text-[11px]"
                                        disabled={
                                            detail.totals.status['2xx'] === 0
                                        }
                                    >
                                        2XX
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="3xx"
                                        className="text-[11px]"
                                        disabled={
                                            detail.totals.status['3xx'] === 0
                                        }
                                    >
                                        3XX
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="4xx"
                                        className="text-[11px]"
                                        disabled={
                                            detail.totals.status['4xx'] === 0
                                        }
                                    >
                                        4XX
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="5xx"
                                        className="text-[11px]"
                                        disabled={
                                            detail.totals.status['5xx'] === 0
                                        }
                                    >
                                        5XX
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="failed"
                                        className="text-[11px]"
                                        disabled={
                                            detail.totals.status.failed === 0
                                        }
                                    >
                                        Failed
                                    </TabsTrigger>
                                </TabsList>
                            </Tabs>
                            <Tabs
                                value={durationFilter}
                                onValueChange={(v) =>
                                    setDurationFilter(v as DurationFilter)
                                }
                            >
                                <TabsList className="h-8">
                                    <TabsTrigger
                                        value="all"
                                        className="text-[11px]"
                                    >
                                        View all
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="avg"
                                        className="text-[11px]"
                                        disabled={detail.totals.avg_ms === null}
                                    >
                                        ≥ AVG
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="p95"
                                        className="text-[11px]"
                                        disabled={detail.totals.p95_ms === null}
                                    >
                                        ≥ P95
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
                                    <TableHead className="w-44 text-[10px] font-semibold tracking-wider uppercase">
                                        Date
                                    </TableHead>
                                    <TableHead className="text-[10px] font-semibold tracking-wider uppercase">
                                        Source
                                    </TableHead>
                                    <TableHead className="w-20 text-[10px] font-semibold tracking-wider uppercase">
                                        Method
                                    </TableHead>
                                    <TableHead className="w-20 text-[10px] font-semibold tracking-wider uppercase">
                                        Status
                                    </TableHead>
                                    <TableHead className="text-[10px] font-semibold tracking-wider uppercase">
                                        URL
                                    </TableHead>
                                    <TableHead className="w-24 text-right text-[10px] font-semibold tracking-wider uppercase">
                                        Duration
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filtered.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={6}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No requests match this filter.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    filtered.map((row) => (
                                        <TableRow
                                            key={row.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <TableCell className="py-2.5 font-mono text-xs text-muted-foreground">
                                                {formatStamp(row.occurred_at)}
                                            </TableCell>
                                            <TableCell className="py-2.5 font-mono text-xs">
                                                {row.source_label ? (
                                                    <span
                                                        title={row.source_label}
                                                        className="block max-w-[260px] truncate"
                                                    >
                                                        {row.source_label}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                                {row.source_type ? (
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {row.source_type}
                                                    </span>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className="py-2.5">
                                                <Badge
                                                    variant="muted"
                                                    className="font-mono text-[10px]"
                                                >
                                                    {row.method}
                                                </Badge>
                                            </TableCell>
                                            <TableCell className="py-2.5">
                                                <StatusBadge
                                                    status={row.status_code}
                                                />
                                            </TableCell>
                                            <TableCell className="py-2.5 text-xs">
                                                <span
                                                    className="block max-w-[480px] truncate font-mono"
                                                    title={row.url}
                                                >
                                                    {row.url}
                                                </span>
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'py-2.5 text-right font-mono text-xs tabular-nums',
                                                    detail.totals.p95_ms !==
                                                        null &&
                                                        (row.duration_ms ??
                                                            0) >=
                                                            detail.totals.p95_ms
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : '',
                                                )}
                                            >
                                                {formatMs(row.duration_ms)}
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

function StatusBadge({ status }: { status: number | null }) {
    if (status === null) {
        return (
            <Badge
                variant="muted"
                className="bg-zinc-200 font-mono text-[10px] text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
            >
                FAILED
            </Badge>
        );
    }

    const color =
        status >= 500
            ? 'bg-rose-100 text-rose-700 dark:bg-rose-950 dark:text-rose-300'
            : status >= 400
              ? 'bg-amber-100 text-amber-700 dark:bg-amber-950 dark:text-amber-300'
              : status >= 300
                ? 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-300'
                : status >= 200
                  ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                  : 'bg-sky-100 text-sky-700 dark:bg-sky-950 dark:text-sky-300';

    return (
        <Badge variant="muted" className={cn('font-mono text-[10px]', color)}>
            {status}
        </Badge>
    );
}

function statusBucket(status: number | null): StatusFilter {
    if (status === null) {
        return 'failed';
    }

    if (status >= 500) {
        return '5xx';
    }

    if (status >= 400) {
        return '4xx';
    }

    if (status >= 300) {
        return '3xx';
    }

    if (status >= 200) {
        return '2xx';
    }

    if (status >= 100) {
        return '1xx';
    }

    return 'failed';
}

function RequestsCard({ detail }: { detail: Detail }) {
    const data = detail.buckets.map((b) => ({
        time: formatTime(b.bucket),
        '1xx': b['1xx'],
        '2xx': b['2xx'],
        '3xx': b['3xx'],
        '4xx': b['4xx'],
        '5xx': b['5xx'],
        failed: b.failed,
    }));

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Requests
                </CardTitle>
                <div className="flex items-center gap-3 text-[10px] tracking-wider text-muted-foreground uppercase">
                    <LegendDot color="bg-sky-400" label="1XX" />
                    <LegendDot color="bg-emerald-500" label="2XX" />
                    <LegendDot color="bg-slate-400" label="3XX" />
                    <LegendDot color="bg-amber-500" label="4XX" />
                    <LegendDot color="bg-rose-500" label="5XX" />
                    <LegendDot
                        color="bg-zinc-700 dark:bg-zinc-500"
                        label="Failed"
                    />
                </div>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-baseline gap-6">
                    <div>
                        <div className="text-3xl font-semibold">
                            {formatNumber(detail.totals.total)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Requests
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <StatusStat
                        label="2XX"
                        value={detail.totals.status['2xx']}
                        accent="text-emerald-600 dark:text-emerald-400"
                    />
                    <StatusStat
                        label="4XX"
                        value={detail.totals.status['4xx']}
                        accent="text-amber-600 dark:text-amber-400"
                    />
                    <StatusStat
                        label="5XX"
                        value={detail.totals.status['5xx']}
                        accent="text-rose-600 dark:text-rose-400"
                    />
                    <StatusStat
                        label="Failed"
                        value={detail.totals.status.failed}
                        accent="text-zinc-700 dark:text-zinc-300"
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
                                dataKey="1xx"
                                stackId="status"
                                fill="#38bdf8"
                            />
                            <Bar
                                dataKey="2xx"
                                stackId="status"
                                fill="#10b981"
                            />
                            <Bar
                                dataKey="3xx"
                                stackId="status"
                                fill="#94a3b8"
                            />
                            <Bar
                                dataKey="4xx"
                                stackId="status"
                                fill="#f59e0b"
                            />
                            <Bar
                                dataKey="5xx"
                                stackId="status"
                                fill="#f43f5e"
                            />
                            <Bar
                                dataKey="failed"
                                stackId="status"
                                fill="#3f3f46"
                                radius={[2, 2, 0, 0]}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
                <div className="mt-2 flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>{formatStamp(detail.buckets[0]?.bucket)}</span>
                    <span>
                        {formatStamp(
                            detail.buckets[detail.buckets.length - 1]?.bucket,
                        )}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function DurationCard({ detail }: { detail: Detail }) {
    const avgData = useMemo(
        () =>
            detail.buckets
                .map((b) => ({
                    x: new Date(b.bucket).getTime(),
                    y: b.avg_duration,
                }))
                .filter(
                    (p): p is { x: number; y: number } =>
                        p.y !== null && p.y > 0,
                ),
        [detail.buckets],
    );

    const p95Data = useMemo(
        () =>
            detail.buckets
                .map((b) => ({
                    x: new Date(b.bucket).getTime(),
                    y: b.p95_duration,
                }))
                .filter(
                    (p): p is { x: number; y: number } =>
                        p.y !== null && p.y > 0,
                ),
        [detail.buckets],
    );

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Duration
                </CardTitle>
                <div className="flex items-center gap-4 text-[10px] tracking-wider text-muted-foreground uppercase">
                    <span className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                        Avg
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        P95
                    </span>
                </div>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-baseline gap-6">
                    <div>
                        <div className="text-3xl font-semibold">
                            {formatMs(detail.totals.min_ms)}
                            <span className="px-2 text-base text-muted-foreground">
                                –
                            </span>
                            {formatMs(detail.totals.max_ms)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Min – Max
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat label="Avg" value={formatMs(detail.totals.avg_ms)} />
                    <Stat label="P95" value={formatMs(detail.totals.p95_ms)} />
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
                                type="number"
                                dataKey="x"
                                domain={['dataMin', 'dataMax']}
                                tick={{
                                    fontSize: 10,
                                    fill: 'var(--color-muted-foreground)',
                                }}
                                tickFormatter={(value) =>
                                    formatTime(new Date(value).toISOString())
                                }
                            />
                            <YAxis
                                type="number"
                                dataKey="y"
                                tick={{
                                    fontSize: 10,
                                    fill: 'var(--color-muted-foreground)',
                                }}
                                width={36}
                                unit="ms"
                            />
                            <ZAxis range={[20, 20]} />
                            <Tooltip
                                cursor={{ strokeDasharray: '3 3' }}
                                contentStyle={tooltipStyle}
                                formatter={(value: number | string) =>
                                    `${Number(value).toFixed(2)} ms`
                                }
                                labelFormatter={(value: number | string) =>
                                    formatStamp(
                                        new Date(Number(value)).toISOString(),
                                    )
                                }
                            />
                            <Scatter data={avgData} fill="#9ca3af" />
                            <Scatter data={p95Data} fill="#f59e0b" />
                        </ScatterChart>
                    </ResponsiveContainer>
                </div>
                <div className="mt-2 flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>{formatStamp(detail.buckets[0]?.bucket)}</span>
                    <span>
                        {formatStamp(
                            detail.buckets[detail.buckets.length - 1]?.bucket,
                        )}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                {label}
            </div>
            <div className="text-base font-semibold">{value}</div>
        </div>
    );
}

function StatusStat({
    label,
    value,
    accent,
}: {
    label: string;
    value: number;
    accent: string;
}) {
    return (
        <div>
            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                {label}
            </div>
            <div className={cn('text-base font-semibold tabular-nums', accent)}>
                {formatNumber(value)}
            </div>
        </div>
    );
}

function LegendDot({ color, label }: { color: string; label: string }) {
    return (
        <span className="flex items-center gap-1.5">
            <span className={cn('h-1.5 w-1.5 rounded-full', color)} />
            {label}
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

    if (value < 1) {
        return `${Math.round(value * 1000)}µs`;
    }

    if (value >= 1000) {
        return `${(value / 1000).toFixed(2)}s`;
    }

    return `${value.toFixed(2)}ms`;
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatStamp(iso: string | null | undefined): string {
    if (!iso) {
        return '';
    }

    const d = new Date(iso);

    return d.toLocaleString([], {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const tooltipStyle = {
    background: 'var(--color-popover)',
    border: '1px solid var(--color-border)',
    borderRadius: 6,
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
} as const;
