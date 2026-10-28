import { Link } from '@inertiajs/react';
import { ExternalLink, Users } from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Line,
    LineChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import commandsRoutes from '@/routes/projects/commands';

type Bucket = {
    bucket: string;
    successful: number;
    failed: number;
    avg_duration: number | null;
    p95_duration: number | null;
};

type Call = {
    id: string;
    command: string;
    exit_code: number | null;
    status: string;
    duration_ms: number | null;
    occurred_at: string | null;
};

type Detail = {
    command: string;
    totals: { total: number; successful: number; failed: number };
    duration: {
        min_ms: number | null;
        max_ms: number | null;
        avg_ms: number | null;
        p95_ms: number | null;
        threshold_ms: number | null;
    };
    buckets: Bucket[];
    calls: Call[];
};

type StatusFilter = 'all' | 'successful' | 'failed';
type DurationFilter = 'all' | 'avg' | 'p95';

type Props = {
    detail: Detail;
    selectedRange: string;
};

export default function CommandsShow({ detail, selectedRange }: Props) {
    const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
    const [durationFilter, setDurationFilter] = useState<DurationFilter>('all');

    const filteredCalls = useMemo(() => {
        return detail.calls.filter((c) => {
            if (statusFilter === 'successful' && c.status !== 'completed') {
                return false;
            }

            if (statusFilter === 'failed' && c.status !== 'failed') {
                return false;
            }

            if (
                durationFilter === 'avg' &&
                detail.duration.avg_ms !== null &&
                c.duration_ms !== null
            ) {
                if (c.duration_ms < detail.duration.avg_ms) {
                    return false;
                }
            }

            if (
                durationFilter === 'p95' &&
                detail.duration.p95_ms !== null &&
                c.duration_ms !== null
            ) {
                if (c.duration_ms < detail.duration.p95_ms) {
                    return false;
                }
            }

            return true;
        });
    }, [
        detail.calls,
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
        <AppLayout title={detail.command}>
            <PageHeader
                title={
                    <div className="space-y-0.5">
                        <div className="text-[11px] font-normal tracking-wider text-muted-foreground uppercase">
                            <Link
                                href={commandsRoutes.index(slug).url}
                                className="hover:text-foreground"
                            >
                                Commands
                            </Link>
                        </div>
                        <div className="font-mono">{detail.command}</div>
                    </div>
                }
                selectedRange={selectedRange}
                actions={
                    <Select value="__all__" onValueChange={() => undefined}>
                        <SelectTrigger className="h-8 w-40 text-xs">
                            <div className="flex items-center gap-1.5">
                                <Users className="h-3.5 w-3.5" />
                                <SelectValue placeholder="All Users" />
                            </div>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All Users</SelectItem>
                        </SelectContent>
                    </Select>
                }
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <CallsCard detail={detail} />
                    <DurationCard detail={detail} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3 space-y-0 pb-3">
                        <CardTitle>{filteredCalls.length} Calls</CardTitle>
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
                                        value="successful"
                                        className="gap-1.5 px-3"
                                    >
                                        Successful
                                        <Badge
                                            variant="muted"
                                            className="px-1.5 text-[10px]"
                                        >
                                            {detail.totals.successful}
                                        </Badge>
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
                                    <TableHead className="w-56">DATE</TableHead>
                                    <TableHead>COMMAND</TableHead>
                                    <TableHead className="w-28">
                                        EXIT CODE
                                    </TableHead>
                                    <TableHead className="w-32 text-right">
                                        DURATION
                                    </TableHead>
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {filteredCalls.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No calls match the current filters
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    filteredCalls.map((call) => (
                                        <TableRow key={call.id}>
                                            <TableCell className="py-2.5 font-mono text-xs text-muted-foreground">
                                                {formatUtc(call.occurred_at)}
                                            </TableCell>
                                            <TableCell className="py-2.5 font-mono text-xs">
                                                {call.command}
                                            </TableCell>
                                            <TableCell className="py-2.5">
                                                <ExitCodeBadge
                                                    code={call.exit_code}
                                                    status={call.status}
                                                />
                                            </TableCell>
                                            <TableCell className="py-2.5 text-right font-mono text-xs">
                                                {formatMs(call.duration_ms)}
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

function CallsCard({ detail }: { detail: Detail }) {
    const { totals, buckets } = detail;
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        Successful: b.successful,
        Failed: b.failed,
    }));

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Calls
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
                            Calls
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
                        label="Successful"
                        value={formatNumber(totals.successful)}
                        accent="text-emerald-600 dark:text-emerald-400"
                        dot="bg-emerald-500"
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
                                dataKey="Successful"
                                stackId="s"
                                fill="#10b981"
                            />
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
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        avg: b.avg_duration ?? 0,
        p95: b.p95_duration ?? 0,
    }));

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
                        accent="text-indigo-600 dark:text-indigo-400"
                        dot="bg-indigo-500"
                    />
                    <Stat
                        label="P95"
                        value={formatMs(duration.p95_ms)}
                        accent="text-violet-600 dark:text-violet-400"
                        dot="bg-amber-500"
                    />
                </div>

                <div className="mt-5 h-40">
                    <ResponsiveContainer width="100%" height="100%">
                        <LineChart data={data}>
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
                                unit="ms"
                            />
                            <Tooltip
                                contentStyle={tooltipStyle}
                                formatter={(value) =>
                                    `${Number(value).toFixed(0)} ms`
                                }
                            />
                            <Line
                                type="monotone"
                                dataKey="avg"
                                stroke="#6366f1"
                                strokeWidth={2}
                                dot={{ r: 2, fill: '#6366f1' }}
                                activeDot={{ r: 4 }}
                            />
                            <Line
                                type="monotone"
                                dataKey="p95"
                                stroke="#f59e0b"
                                strokeWidth={2}
                                dot={{ r: 2, fill: '#f59e0b' }}
                            />
                        </LineChart>
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

function ExitCodeBadge({
    code,
    status,
}: {
    code: number | null;
    status: string;
}) {
    if (code === null) {
        return (
            <span className="font-mono text-xs text-muted-foreground">—</span>
        );
    }

    const isFailed = status === 'failed' || code !== 0;

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md border px-2 py-0.5 font-mono text-[10px] font-semibold',
                isFailed
                    ? 'border-rose-500/40 text-rose-600 dark:text-rose-400'
                    : 'border-emerald-500/40 text-emerald-600 dark:text-emerald-400',
            )}
        >
            {code}
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
