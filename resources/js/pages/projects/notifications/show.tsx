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
import notificationsRoutes from '@/routes/projects/notifications';
import type { SharedProps } from '@/types/inertia';

type Bucket = {
    bucket: string;
    count: number;
    avg_duration: number | null;
    p95_duration: number | null;
};

type Send = {
    id: string;
    channel: string;
    source_type: string | null;
    source_label: string | null;
    duration_ms: number | null;
    occurred_at: string | null;
};

type Detail = {
    notification_class: string;
    hash: string;
    totals: {
        total: number;
        total_ms: number;
        min_ms: number | null;
        max_ms: number | null;
        avg_ms: number | null;
        p95_ms: number | null;
    };
    buckets: Bucket[];
    sends: Send[];
};

type Props = {
    detail: Detail;
    selectedRange: string;
};

type Filter = 'all' | 'avg' | 'p95';

export default function NotificationShow({ detail, selectedRange }: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    const [filter, setFilter] = useState<Filter>('all');

    const filtered = useMemo(() => {
        if (filter === 'avg' && detail.totals.avg_ms !== null) {
            return detail.sends.filter(
                (s) => (s.duration_ms ?? 0) >= (detail.totals.avg_ms ?? 0),
            );
        }

        if (filter === 'p95' && detail.totals.p95_ms !== null) {
            return detail.sends.filter(
                (s) => (s.duration_ms ?? 0) >= (detail.totals.p95_ms ?? 0),
            );
        }

        return detail.sends;
    }, [detail.sends, detail.totals.avg_ms, detail.totals.p95_ms, filter]);

    return (
        <AppLayout title="Notification detail">
            <PageHeader
                title={
                    <span className="flex flex-col gap-1">
                        <span className="text-xs font-normal tracking-wider text-muted-foreground uppercase">
                            Notifications
                        </span>
                        <span className="block max-w-[920px] font-mono text-base leading-snug font-medium break-words">
                            {detail.notification_class}
                        </span>
                    </span>
                }
                breadcrumbs={[
                    { label: 'Activity' },
                    {
                        label: 'Notifications',
                        href: notificationsRoutes.index(slug).url,
                    },
                    { label: 'Detail' },
                ]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <NotificationsCard detail={detail} />
                    <DurationCard detail={detail} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle>Sends</CardTitle>
                        <Tabs
                            value={filter}
                            onValueChange={(v) => setFilter(v as Filter)}
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
                                    <TableHead className="w-32 text-[10px] font-semibold tracking-wider uppercase">
                                        Channel
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
                                            colSpan={4}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No sends in this view.
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    filtered.map((send) => (
                                        <TableRow
                                            key={send.id}
                                            className="hover:bg-muted/30"
                                        >
                                            <TableCell className="py-2.5 font-mono text-xs text-muted-foreground">
                                                {formatStamp(send.occurred_at)}
                                            </TableCell>
                                            <TableCell className="py-2.5 font-mono text-xs">
                                                {send.source_label ? (
                                                    <span
                                                        title={
                                                            send.source_label
                                                        }
                                                        className="block max-w-[360px] truncate"
                                                    >
                                                        {send.source_label}
                                                    </span>
                                                ) : (
                                                    <span className="text-muted-foreground">
                                                        —
                                                    </span>
                                                )}
                                                {send.source_type ? (
                                                    <span className="text-[10px] text-muted-foreground">
                                                        {send.source_type}
                                                    </span>
                                                ) : null}
                                            </TableCell>
                                            <TableCell className="py-2.5">
                                                <Badge
                                                    variant="muted"
                                                    className="font-mono text-[10px]"
                                                >
                                                    {send.channel}
                                                </Badge>
                                            </TableCell>
                                            <TableCell
                                                className={cn(
                                                    'py-2.5 text-right font-mono text-xs tabular-nums',
                                                    detail.totals.p95_ms !==
                                                        null &&
                                                        (send.duration_ms ??
                                                            0) >=
                                                            detail.totals.p95_ms
                                                        ? 'text-amber-600 dark:text-amber-400'
                                                        : '',
                                                )}
                                            >
                                                {formatMs(send.duration_ms)}
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

function NotificationsCard({ detail }: { detail: Detail }) {
    const data = detail.buckets.map((b) => ({
        time: formatTime(b.bucket),
        Notifications: b.count,
    }));

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Notifications
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="text-3xl font-semibold">
                    {formatNumber(detail.totals.total)}
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
                                dataKey="Notifications"
                                fill="#94a3b8"
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
                                formatter={(value) =>
                                    `${Number(value).toFixed(2)} ms`
                                }
                                labelFormatter={(value) =>
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
