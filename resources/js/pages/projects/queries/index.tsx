import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Database,
    ExternalLink,
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
import queriesRoutes from '@/routes/projects/queries';
import type { SharedProps } from '@/types/inertia';
import type { Paginated } from '@/types/pagination';

type SummaryBucket = {
    bucket: string;
    count: number;
    avg_duration: number | null;
    p95_duration: number | null;
};

type Summary = {
    totals: {
        total: number;
        total_ms: number;
        min_ms: number | null;
        max_ms: number | null;
        avg_ms: number | null;
        p95_ms: number | null;
    };
    buckets: SummaryBucket[];
};

type QueryRow = {
    hash: string;
    sql: string;
    connection: string | null;
    total: number;
    total_ms: number;
    avg_ms: number | null;
    p95_ms: number | null;
};

type Props = {
    summary: Summary;
    queries: Paginated<QueryRow>;
    selectedRange: string;
    filters: { search: string | null; sort: SortKey; dir: SortDir };
};

type SortKey =
    | 'sql'
    | 'connection'
    | 'total'
    | 'total_ms'
    | 'avg_ms'
    | 'p95_ms';
type SortDir = 'asc' | 'desc';

export default function QueriesIndex({
    summary,
    queries,
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
                : key === 'sql' || key === 'connection'
                  ? 'asc'
                  : 'desc';
        visit({ sort: key, dir: nextDir, page: null });
    };

    const onSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ search: search || null, page: null });
    };

    return (
        <AppLayout title="Queries">
            <PageHeader
                title="Queries"
                breadcrumbs={[{ label: 'Activity' }, { label: 'Queries' }]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <CallsCard summary={summary} />
                    <DurationCard summary={summary} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle className="flex items-center gap-2">
                            <Database className="h-4 w-4 text-muted-foreground" />
                            <span>
                                {queries.total.toLocaleString()} Queries
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
                                placeholder="Search queries"
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
                                        label="QUERY"
                                        sortKey="sql"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                    />
                                    <SortableHead
                                        label="CONNECTION"
                                        sortKey="connection"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        className="w-32"
                                    />
                                    <SortableHead
                                        label="CALLS"
                                        sortKey="total"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="TOTAL"
                                        sortKey="total_ms"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="AVG"
                                        sortKey="avg_ms"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="P95"
                                        sortKey="p95_ms"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <TableHead className="w-10" />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {queries.data.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={7}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No queries captured in{' '}
                                            {selectedRange.toUpperCase()}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    queries.data.map((row) => (
                                        <QueryRowTr
                                            key={row.hash}
                                            row={row}
                                            slug={slug}
                                        />
                                    ))
                                )}
                            </TableBody>
                        </Table>
                        <Pagination
                            links={queries.links}
                            from={queries.from}
                            to={queries.to}
                            total={queries.total}
                        />
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}

function QueryRowTr({ row, slug }: { row: QueryRow; slug: string }) {
    const href = queriesRoutes.show([slug, row.hash]).url;

    return (
        <TableRow className="cursor-pointer hover:bg-muted/50">
            <TableCell className="py-2.5">
                <Link
                    href={href}
                    className="flex items-center gap-2 font-mono text-xs hover:text-foreground"
                >
                    <Database className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                    <SqlPreview sql={row.sql} />
                </Link>
            </TableCell>
            <TableCell className="py-2.5 font-mono text-xs text-muted-foreground">
                {row.connection ?? '—'}
            </TableCell>
            <Numeric value={formatNumber(row.total)} />
            <Numeric value={formatMs(row.total_ms)} raw muted />
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

function SqlPreview({ sql }: { sql: string }) {
    const condensed = sql.replace(/\s+/g, ' ').trim();

    return (
        <span className="block max-w-[640px] truncate" title={condensed}>
            <SqlInline sql={condensed} />
        </span>
    );
}

const SQL_KEYWORDS = new Set([
    'select',
    'from',
    'where',
    'and',
    'or',
    'on',
    'as',
    'in',
    'is',
    'not',
    'null',
    'inner',
    'outer',
    'left',
    'right',
    'join',
    'group',
    'by',
    'order',
    'limit',
    'offset',
    'insert',
    'into',
    'values',
    'update',
    'set',
    'delete',
    'returning',
    'between',
    'like',
    'asc',
    'desc',
    'count',
    'sum',
    'avg',
    'min',
    'max',
    'distinct',
    'having',
    'union',
    'all',
    'case',
    'when',
    'then',
    'else',
    'end',
]);

function SqlInline({ sql }: { sql: string }) {
    const tokens = sql.split(/(\s+|`[^`]+`|'[^']*'|\?|\(|\)|,)/g);

    return (
        <>
            {tokens.map((tok, i) => {
                if (tok === '' || tok === undefined) {
                    return null;
                }

                const lower = tok.toLowerCase();

                if (SQL_KEYWORDS.has(lower)) {
                    return (
                        <span
                            key={i}
                            className="text-sky-600 dark:text-sky-400"
                        >
                            {tok}
                        </span>
                    );
                }

                if (tok.startsWith('`') && tok.endsWith('`')) {
                    return (
                        <span
                            key={i}
                            className="text-amber-600 dark:text-amber-400"
                        >
                            {tok}
                        </span>
                    );
                }

                if (tok === '?' || /^[0-9]+$/.test(tok)) {
                    return (
                        <span
                            key={i}
                            className="text-rose-500 dark:text-rose-400"
                        >
                            {tok}
                        </span>
                    );
                }

                return <span key={i}>{tok}</span>;
            })}
        </>
    );
}

function Numeric({
    value,
    muted,
    raw,
}: {
    value: number | string;
    muted?: boolean;
    raw?: boolean;
}) {
    const text = raw
        ? String(value)
        : typeof value === 'number'
          ? formatNumber(value)
          : value;

    return (
        <TableCell
            className={cn(
                'py-2.5 text-right font-mono text-xs',
                muted && 'text-muted-foreground',
            )}
        >
            {text}
        </TableCell>
    );
}

function CallsCard({ summary }: { summary: Summary }) {
    const { totals, buckets } = summary;
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        Calls: b.count,
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
                            {formatCompact(totals.total)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Calls
                        </div>
                    </div>
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
                                dataKey="Calls"
                                fill="#94a3b8"
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
