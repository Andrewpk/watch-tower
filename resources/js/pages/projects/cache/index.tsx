import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Database,
    Search,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

import { PageHeader } from '@/components/page-header';
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

type EventBucket = {
    bucket: string;
    hit: number;
    miss: number;
    write: number;
    delete: number;
};

type FailureBucket = {
    bucket: string;
    write: number;
    delete: number;
};

type Summary = {
    totals: {
        hit: number;
        miss: number;
        write: number;
        delete: number;
        total: number;
    };
    buckets: EventBucket[];
};

type Failures = {
    totals: { write: number; delete: number; total: number };
    buckets: FailureBucket[];
};

type KeyRow = {
    key: string;
    hash: string;
    hit_pct: number | null;
    hit: number;
    miss: number;
    write: number;
    delete: number;
    failures: number;
    total: number;
};

type Props = {
    summary: Summary;
    failures: Failures;
    keys: KeyRow[];
    selectedRange: string;
    filters: { search: string | null };
};

type SortKey =
    | 'key'
    | 'hit_pct'
    | 'hit'
    | 'miss'
    | 'write'
    | 'delete'
    | 'failures'
    | 'total';
type SortDir = 'asc' | 'desc';

const HIT_COLOR = '#94a3b8';
const MISS_COLOR = '#f97316';
const WRITE_COLOR = '#3b82f6';
const DELETE_COLOR = '#f43f5e';

export default function CacheIndex({
    summary,
    failures,
    keys,
    selectedRange,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [sortKey, setSortKey] = useState<SortKey>('total');
    const [sortDir, setSortDir] = useState<SortDir>('desc');

    const onSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const url = new URL(window.location.href);

        if (search) {
            url.searchParams.set('search', search);
        } else {
            url.searchParams.delete('search');
        }

        router.visit(url.pathname + url.search, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const toggleSort = (key: SortKey) => {
        if (sortKey === key) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortDir(key === 'key' ? 'asc' : 'desc');
        }
    };

    const sortedKeys = useMemo(() => {
        const sorted = [...keys];
        sorted.sort((a, b) => {
            const av = a[sortKey];
            const bv = b[sortKey];

            if (av === null && bv === null) {
                return 0;
            }

            if (av === null) {
                return 1;
            }

            if (bv === null) {
                return -1;
            }

            const cmp =
                sortKey === 'key'
                    ? String(av).localeCompare(String(bv))
                    : Number(av) - Number(bv);

            return sortDir === 'asc' ? cmp : -cmp;
        });

        return sorted;
    }, [keys, sortKey, sortDir]);

    return (
        <AppLayout title="Cache">
            <PageHeader
                title="Cache"
                breadcrumbs={[{ label: 'Activity' }, { label: 'Cache' }]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <EventsCard summary={summary} />
                    <FailuresCard failures={failures} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle className="flex items-center gap-2">
                            <Database className="h-4 w-4 text-muted-foreground" />
                            <span>Top 100 keys</span>
                        </CardTitle>
                        <form
                            onSubmit={onSearchSubmit}
                            className="relative w-64"
                        >
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search keys"
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
                                        label="KEY"
                                        sortKey="key"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                    />
                                    <SortableHead
                                        label="HIT %"
                                        sortKey="hit_pct"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="HITS"
                                        sortKey="hit"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="MISSES"
                                        sortKey="miss"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="WRITES"
                                        sortKey="write"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="DELETES"
                                        sortKey="delete"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="FAILURES"
                                        sortKey="failures"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="TOTAL"
                                        sortKey="total"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sortedKeys.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={8}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No cache events captured in{' '}
                                            {selectedRange.toUpperCase()}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    sortedKeys.map((row) => (
                                        <TableRow
                                            key={row.hash}
                                            className="hover:bg-muted/30"
                                        >
                                            <TableCell className="py-2.5">
                                                <span className="flex items-center gap-2 font-mono text-xs">
                                                    <Database className="h-3.5 w-3.5 shrink-0 text-emerald-500" />
                                                    <span
                                                        className="block max-w-120 truncate"
                                                        title={row.key}
                                                    >
                                                        {row.key}
                                                    </span>
                                                </span>
                                            </TableCell>
                                            <Numeric
                                                value={
                                                    row.hit_pct === null
                                                        ? '—'
                                                        : `${row.hit_pct}%`
                                                }
                                                raw
                                                muted={row.hit_pct === null}
                                            />
                                            <Numeric
                                                value={formatNumber(row.hit)}
                                            />
                                            <Numeric
                                                value={formatNumber(row.miss)}
                                                muted={row.miss === 0}
                                            />
                                            <Numeric
                                                value={formatNumber(row.write)}
                                                muted={row.write === 0}
                                            />
                                            <Numeric
                                                value={formatNumber(row.delete)}
                                                muted={row.delete === 0}
                                            />
                                            <TableCell
                                                className={cn(
                                                    'py-2.5 text-right font-mono text-xs tabular-nums',
                                                    row.failures > 0
                                                        ? 'text-rose-600 dark:text-rose-400'
                                                        : 'text-muted-foreground',
                                                )}
                                            >
                                                {formatNumber(row.failures)}
                                            </TableCell>
                                            <Numeric
                                                value={formatNumber(row.total)}
                                            />
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
                'py-2.5 text-right font-mono text-xs tabular-nums',
                muted && 'text-muted-foreground',
            )}
        >
            {text}
        </TableCell>
    );
}

function EventsCard({ summary }: { summary: Summary }) {
    const { totals, buckets } = summary;
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        deletes: b.delete,
        hits: b.hit,
        misses: b.miss,
        writes: b.write,
    }));

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Events
                </CardTitle>
                <div className="flex items-center gap-4 text-[10px] tracking-wider text-muted-foreground uppercase">
                    <LegendDot label="Deletes" color={DELETE_COLOR} />
                    <LegendDot label="Hits" color={HIT_COLOR} />
                    <LegendDot label="Misses" color={MISS_COLOR} />
                    <LegendDot label="Writes" color={WRITE_COLOR} />
                </div>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-end gap-6">
                    <div>
                        <div className="text-3xl font-semibold">
                            {formatCompact(totals.total)}
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="Deletes"
                        value={formatCompact(totals.delete)}
                        dotColor={DELETE_COLOR}
                    />
                    <Stat
                        label="Hits"
                        value={formatCompact(totals.hit)}
                        dotColor={HIT_COLOR}
                    />
                    <Stat
                        label="Misses"
                        value={formatCompact(totals.miss)}
                        dotColor={MISS_COLOR}
                    />
                    <Stat
                        label="Writes"
                        value={formatCompact(totals.write)}
                        dotColor={WRITE_COLOR}
                    />
                </div>

                <div className="mt-5 h-44">
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
                            <Bar dataKey="hits" stackId="a" fill={HIT_COLOR} />
                            <Bar
                                dataKey="misses"
                                stackId="a"
                                fill={MISS_COLOR}
                            />
                            <Bar
                                dataKey="writes"
                                stackId="a"
                                fill={WRITE_COLOR}
                            />
                            <Bar
                                dataKey="deletes"
                                stackId="a"
                                fill={DELETE_COLOR}
                                radius={[2, 2, 0, 0]}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
                <div className="mt-2 flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>{formatStamp(buckets[0]?.bucket)}</span>
                    <span>
                        {formatStamp(buckets[buckets.length - 1]?.bucket)}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function FailuresCard({ failures }: { failures: Failures }) {
    const { totals, buckets } = failures;
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        delete: b.delete,
        write: b.write,
    }));

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between space-y-0 pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Failures
                </CardTitle>
                <div className="flex items-center gap-4 text-[10px] tracking-wider text-muted-foreground uppercase">
                    <LegendDot label="Delete" color={DELETE_COLOR} />
                    <LegendDot label="Write" color={MISS_COLOR} />
                </div>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-end gap-6">
                    <div>
                        <div className="text-3xl font-semibold">
                            {formatCompact(totals.total)}
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="Delete"
                        value={formatCompact(totals.delete)}
                        dotColor={DELETE_COLOR}
                    />
                    <Stat
                        label="Write"
                        value={formatCompact(totals.write)}
                        dotColor={MISS_COLOR}
                    />
                </div>

                <div className="mt-5 h-44">
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
                                dataKey="delete"
                                stackId="a"
                                fill={DELETE_COLOR}
                            />
                            <Bar
                                dataKey="write"
                                stackId="a"
                                fill={MISS_COLOR}
                                radius={[2, 2, 0, 0]}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
                <div className="mt-2 flex items-center justify-between text-[10px] text-muted-foreground">
                    <span>{formatStamp(buckets[0]?.bucket)}</span>
                    <span>
                        {formatStamp(buckets[buckets.length - 1]?.bucket)}
                    </span>
                </div>
            </CardContent>
        </Card>
    );
}

function LegendDot({ label, color }: { label: string; color: string }) {
    return (
        <span className="flex items-center gap-1.5">
            <span
                className="h-1.5 w-1.5 rounded-full"
                style={{ background: color }}
            />
            {label}
        </span>
    );
}

function Stat({
    label,
    value,
    dotColor,
}: {
    label: string;
    value: string;
    dotColor?: string;
}) {
    return (
        <div>
            <div className="flex items-center gap-1.5 text-[10px] tracking-wider text-muted-foreground uppercase">
                {dotColor ? (
                    <span
                        className="h-1.5 w-1.5 rounded-full"
                        style={{ background: dotColor }}
                    />
                ) : null}
                {label}
            </div>
            <div className="text-base font-semibold tabular-nums">{value}</div>
        </div>
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
