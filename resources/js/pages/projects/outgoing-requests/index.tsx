import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ExternalLink,
    Globe,
    Search,
} from 'lucide-react';
import { useState } from 'react';
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
import { Pagination } from '@/components/pagination';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { AppLayout } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import httpClientRoutes from '@/routes/projects/http-client';
import type { SharedProps } from '@/types/inertia';
import type { Paginated } from '@/types/pagination';

type SummaryBucket = {
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

type Summary = {
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
    buckets: SummaryBucket[];
};

type DomainRow = {
    host: string;
    hash: string;
    count: number;
    avg_ms: number | null;
    p95_ms: number | null;
    failed: number;
    '1xx': number;
    '2xx': number;
    '3xx': number;
    '4xx': number;
    '5xx': number;
};

type SortKey =
    | 'host'
    | 'count'
    | 'avg_ms'
    | 'p95_ms'
    | 'failed'
    | '1xx'
    | '2xx'
    | '3xx'
    | '4xx'
    | '5xx';
type SortDir = 'asc' | 'desc';

type Props = {
    summary: Summary;
    domains: Paginated<DomainRow>;
    selectedRange: string;
    filters: { search: string | null; sort: SortKey; dir: SortDir };
};

export default function OutgoingRequestsIndex({
    summary,
    domains,
    selectedRange,
    filters,
}: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    const [search, setSearch] = useState(filters.search ?? '');
    const sortKey = filters.sort;
    const sortDir = filters.dir;

    const visit = (params: Record<string, string | number | null>) => {
        const url = new URL(window.location.href);

        for (const [key, value] of Object.entries(params)) {
            if (value === null || value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, String(value));
            }
        }

        router.visit(url.pathname + url.search, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const toggleSort = (key: SortKey) => {
        const nextDir: SortDir =
            sortKey === key
                ? sortDir === 'asc'
                    ? 'desc'
                    : 'asc'
                : key === 'host'
                  ? 'asc'
                  : 'desc';
        visit({ sort: key, dir: nextDir, page: null });
    };

    const onSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ search: search || null, page: null });
    };

    return (
        <AppLayout title="Outgoing Requests">
            <PageHeader
                title="Outgoing Requests"
                breadcrumbs={[{ label: 'Activity' }, { label: 'HTTP Client' }]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <RequestsCard summary={summary} />
                    <DurationCard summary={summary} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle className="flex items-center gap-2">
                            <Globe className="h-4 w-4 text-muted-foreground" />
                            <span>
                                {domains.total.toLocaleString()} Domains
                            </span>
                        </CardTitle>
                        <form
                            onSubmit={onSearchSubmit}
                            className="relative w-64"
                        >
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search host"
                                className="h-8 pl-8 text-xs"
                            />
                        </form>
                    </CardHeader>
                    <Separator />
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <SortableHead
                                        label="DOMAIN"
                                        sortKey="host"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                    />
                                    <SortableHead
                                        label="COUNT"
                                        sortKey="count"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="1XX"
                                        sortKey="1xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-16"
                                    />
                                    <SortableHead
                                        label="2XX"
                                        sortKey="2xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-16"
                                    />
                                    <SortableHead
                                        label="3XX"
                                        sortKey="3xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-16"
                                    />
                                    <SortableHead
                                        label="4XX"
                                        sortKey="4xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-16"
                                    />
                                    <SortableHead
                                        label="5XX"
                                        sortKey="5xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-16"
                                    />
                                    <SortableHead
                                        label="FAILED"
                                        sortKey="failed"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="AVG"
                                        sortKey="avg_ms"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="P95"
                                        sortKey="p95_ms"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {domains.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={11}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No outgoing requests in{' '}
                                            {selectedRange.toUpperCase()}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    domains.data.map((row) => (
                                        <DomainRowTr
                                            key={row.hash}
                                            row={row}
                                            slug={slug}
                                        />
                                    ))
                                )}
                            </TableBody>
                        </Table>
                        <Pagination
                            links={domains.links}
                            from={domains.from}
                            to={domains.to}
                            total={domains.total}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function DomainRowTr({ row, slug }: { row: DomainRow; slug: string }) {
    const href = httpClientRoutes.show([slug, row.hash]).url;

    return (
        <TableRow className="cursor-pointer hover:bg-muted/50">
            <TableCell className="py-2.5">
                <Link
                    href={href}
                    className="flex items-center gap-2 font-mono text-xs hover:text-foreground"
                >
                    <Globe className="h-3.5 w-3.5 shrink-0 text-sky-500" />
                    <span className="block max-w-160 truncate" title={row.host}>
                        {row.host}
                    </span>
                </Link>
            </TableCell>
            <Numeric value={formatNumber(row.count)} />
            <Numeric
                value={row['1xx'] === 0 ? '—' : formatNumber(row['1xx'])}
                raw
                muted={row['1xx'] === 0}
            />
            <Numeric
                value={row['2xx'] === 0 ? '—' : formatNumber(row['2xx'])}
                raw
                muted={row['2xx'] === 0}
                accent={
                    row['2xx'] > 0
                        ? 'text-emerald-600 dark:text-emerald-400'
                        : undefined
                }
            />
            <Numeric
                value={row['3xx'] === 0 ? '—' : formatNumber(row['3xx'])}
                raw
                muted={row['3xx'] === 0}
            />
            <Numeric
                value={row['4xx'] === 0 ? '—' : formatNumber(row['4xx'])}
                raw
                muted={row['4xx'] === 0}
                accent={
                    row['4xx'] > 0
                        ? 'text-amber-600 dark:text-amber-400'
                        : undefined
                }
            />
            <Numeric
                value={row['5xx'] === 0 ? '—' : formatNumber(row['5xx'])}
                raw
                muted={row['5xx'] === 0}
                accent={
                    row['5xx'] > 0
                        ? 'text-rose-600 dark:text-rose-400'
                        : undefined
                }
            />
            <Numeric
                value={row.failed === 0 ? '—' : formatNumber(row.failed)}
                raw
                muted={row.failed === 0}
                accent={
                    row.failed > 0
                        ? 'text-rose-600 dark:text-rose-400'
                        : undefined
                }
            />
            <Numeric value={formatMs(row.avg_ms)} raw muted />
            <Numeric value={formatMs(row.p95_ms)} raw muted />
            <TableCell className="py-2.5 text-right">
                <Link
                    href={href}
                    className="inline-flex text-muted-foreground hover:text-foreground"
                >
                    <ExternalLink className="h-3.5 w-3.5" />
                </Link>
            </TableCell>
        </TableRow>
    );
}

function Numeric({
    value,
    muted,
    raw,
    accent,
}: {
    value: number | string;
    muted?: boolean;
    raw?: boolean;
    accent?: string;
}) {
    const text = raw
        ? String(value)
        : typeof value === 'number'
          ? formatNumber(value)
          : value;

    return (
        <TableCell
            className={cn(
                'py-2.5 text-right font-mono text-xs tabular-nums',
                muted && 'text-muted-foreground',
                accent,
            )}
        >
            {text}
        </TableCell>
    );
}

function RequestsCard({ summary }: { summary: Summary }) {
    const { totals, buckets } = summary;
    const data = buckets.map((b) => ({
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
                            {formatCompact(totals.total)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Requests
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <StatusStat
                        label="2XX"
                        value={totals.status['2xx']}
                        accent="text-emerald-600 dark:text-emerald-400"
                    />
                    <StatusStat
                        label="4XX"
                        value={totals.status['4xx']}
                        accent="text-amber-600 dark:text-amber-400"
                    />
                    <StatusStat
                        label="5XX"
                        value={totals.status['5xx']}
                        accent="text-rose-600 dark:text-rose-400"
                    />
                    <StatusStat
                        label="Failed"
                        value={totals.status.failed}
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
            </CardContent>
        </Card>
    );
}

function DurationCard({ summary }: { summary: Summary }) {
    const { totals, buckets } = summary;
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
                            {formatMs(totals.min_ms)}
                            <span className="px-2 text-base text-muted-foreground">
                                –
                            </span>
                            {formatMs(totals.max_ms)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Min – Max
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="Avg"
                        value={formatMs(totals.avg_ms)}
                        accent="text-indigo-600 dark:text-indigo-400"
                        dot="bg-indigo-500"
                    />
                    <Stat
                        label="P95"
                        value={formatMs(totals.p95_ms)}
                        accent="text-amber-600 dark:text-amber-400"
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
                                    `${Number(value).toFixed(2)} ms`
                                }
                            />
                            <Line
                                type="monotone"
                                dataKey="avg"
                                stroke="#9ca3af"
                                strokeWidth={1.5}
                                dot={false}
                            />
                            <Line
                                type="monotone"
                                dataKey="p95"
                                stroke="#f59e0b"
                                strokeWidth={1.5}
                                dot={false}
                            />
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}

function SortableHead({
    label,
    sortKey,
    current,
    dir,
    onSort,
    align,
    className,
}: {
    label: string;
    sortKey: SortKey;
    current: SortKey;
    dir: SortDir;
    onSort: (key: SortKey) => void;
    align?: 'right';
    className?: string;
}) {
    const isActive = current === sortKey;
    const Icon = !isActive ? ArrowUpDown : dir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead className={cn(align === 'right' && 'text-right', className)}>
            <button
                type="button"
                onClick={() => onSort(sortKey)}
                className={cn(
                    'inline-flex items-center gap-1 text-[10px] font-semibold tracking-wider uppercase',
                    align === 'right' && 'flex-row-reverse',
                    isActive
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                )}
            >
                {label}
                <Icon className="h-3 w-3" />
            </button>
        </TableHead>
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

function formatCompact(value: number): string {
    if (value >= 1_000_000) {
        return `${(value / 1_000_000).toFixed(value % 1_000_000 === 0 ? 0 : 1)}M`;
    }

    if (value >= 1_000) {
        return `${(value / 1_000).toFixed(value % 1_000 === 0 ? 0 : 1)}K`;
    }

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

const tooltipStyle = {
    background: 'var(--color-popover)',
    border: '1px solid var(--color-border)',
    borderRadius: 6,
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
} as const;
