import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ExternalLink,
    Search,
    Users,
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
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
import exceptions from '@/routes/projects/exceptions';
import type { SharedProps } from '@/types/inertia';

type Bucket = {
    bucket: string;
    handled: number;
    unhandled: number;
};

type Summary = {
    totals: { total: number; handled: number; unhandled: number };
    buckets: Bucket[];
};

type ExceptionRow = {
    id: string;
    display_number: number | null;
    exception_class: string;
    short_class: string;
    first_message: string;
    last_occurrence_at: string | null;
    total_count: number;
    users_count: number;
    is_handled: boolean;
};

type EndUser = { id: string; email: string | null; count: number };

type Props = {
    summary: Summary;
    groups: ExceptionRow[];
    users: EndUser[];
    selectedRange: string;
    filters: {
        filter: string;
        user_id: string | null;
        search: string | null;
    };
};

type SortKey =
    | 'last_occurrence_at'
    | 'short_class'
    | 'total_count'
    | 'users_count';
type SortDir = 'asc' | 'desc';

export default function ExceptionsIndex({
    summary,
    groups,
    users,
    selectedRange,
    filters,
}: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    const [search, setSearch] = useState(filters.search ?? '');
    const [sortKey, setSortKey] = useState<SortKey>('last_occurrence_at');
    const [sortDir, setSortDir] = useState<SortDir>('desc');

    const sortedGroups = useMemo(() => {
        const copy = [...groups];
        copy.sort((a, b) => {
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

            if (typeof av === 'number' && typeof bv === 'number') {
                return sortDir === 'asc' ? av - bv : bv - av;
            }

            return sortDir === 'asc'
                ? String(av).localeCompare(String(bv))
                : String(bv).localeCompare(String(av));
        });

        return copy;
    }, [groups, sortKey, sortDir]);

    const toggleSort = (key: SortKey) => {
        if (sortKey === key) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortDir(key === 'short_class' ? 'asc' : 'desc');
        }
    };

    const updateQuery = (updates: Record<string, string | null>) => {
        const url = new URL(window.location.href);

        for (const [key, value] of Object.entries(updates)) {
            if (value === null || value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, value);
            }
        }

        router.visit(url.pathname + url.search, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const onSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        updateQuery({ search: search || null });
    };

    const onFilterChange = (value: string) => {
        updateQuery({ filter: value === 'all' ? null : value });
    };

    const onUserChange = (value: string) => {
        updateQuery({ user_id: value === '__all__' ? null : value });
    };

    return (
        <AppLayout title="Exceptions">
            <PageHeader
                title="Exceptions"
                breadcrumbs={[{ label: 'Activity' }, { label: 'Exceptions' }]}
                selectedRange={selectedRange}
                actions={
                    <Select
                        value={filters.user_id ?? '__all__'}
                        onValueChange={onUserChange}
                    >
                        <SelectTrigger className="h-8 w-40 text-xs">
                            <div className="flex items-center gap-1.5">
                                <Users className="h-3.5 w-3.5" />
                                <SelectValue placeholder="All Users" />
                            </div>
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__all__">All Users</SelectItem>
                            {users.map((user) => (
                                <SelectItem key={user.id} value={user.id}>
                                    {user.email ?? user.id} ({user.count})
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                }
            />

            <div className="space-y-6 px-6 py-6">
                <OccurrencesCard summary={summary} />

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle>
                            {sortedGroups.length} Exception
                            {sortedGroups.length === 1 ? '' : 's'}
                        </CardTitle>
                        <div className="flex items-center gap-2">
                            <form
                                onSubmit={onSearchSubmit}
                                className="relative w-64"
                            >
                                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search exceptions"
                                    className="h-8 pl-8 text-xs"
                                />
                            </form>
                            <Tabs
                                value={filters.filter}
                                onValueChange={onFilterChange}
                            >
                                <TabsList className="h-8">
                                    <TabsTrigger
                                        value="all"
                                        className="gap-2 px-3 text-xs"
                                    >
                                        View all
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="handled"
                                        className="gap-2 px-3 text-xs"
                                    >
                                        Handled
                                        {summary.totals.handled > 0 && (
                                            <CountBadge
                                                value={summary.totals.handled}
                                                active={
                                                    filters.filter === 'handled'
                                                }
                                            />
                                        )}
                                    </TabsTrigger>
                                    <TabsTrigger
                                        value="unhandled"
                                        className="gap-2 px-3 text-xs"
                                    >
                                        Unhandled
                                        {summary.totals.unhandled > 0 && (
                                            <CountBadge
                                                value={summary.totals.unhandled}
                                                active={
                                                    filters.filter ===
                                                    'unhandled'
                                                }
                                                accent="destructive"
                                            />
                                        )}
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
                                    <SortableHead
                                        label="LAST SEEN"
                                        sortKey="last_occurrence_at"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        className="w-32"
                                    />
                                    <SortableHead
                                        label="EXCEPTION"
                                        sortKey="short_class"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                    />
                                    <SortableHead
                                        label="COUNT"
                                        sortKey="total_count"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="USERS"
                                        sortKey="users_count"
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
                                {sortedGroups.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={5}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No exceptions captured in{' '}
                                            {selectedRange.toUpperCase()}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    sortedGroups.map((row) => (
                                        <ExceptionRow
                                            key={row.id}
                                            row={row}
                                            slug={slug}
                                        />
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

function ExceptionRow({ row, slug }: { row: ExceptionRow; slug: string }) {
    const href = exceptions.show([slug, row.id]).url;

    return (
        <TableRow className="cursor-pointer hover:bg-muted/40">
            <TableCell className="py-3 text-xs text-muted-foreground">
                <Link href={href} className="block">
                    {formatRelative(row.last_occurrence_at)}
                </Link>
            </TableCell>
            <TableCell className="py-3">
                <Link href={href} className="block">
                    <div className="flex items-start gap-2">
                        <HandledBadge handled={row.is_handled} />
                        <div className="min-w-0">
                            <div className="flex items-center gap-2">
                                <span className="font-mono text-sm font-medium text-foreground">
                                    {row.short_class}
                                </span>
                                <span className="truncate text-sm text-muted-foreground">
                                    {row.first_message}
                                </span>
                            </div>
                        </div>
                    </div>
                </Link>
            </TableCell>
            <TableCell className="py-3 text-right font-mono text-xs tabular-nums">
                <Link href={href} className="block">
                    {formatNumber(row.total_count)}
                </Link>
            </TableCell>
            <TableCell className="py-3 text-right font-mono text-xs text-muted-foreground tabular-nums">
                <Link href={href} className="block">
                    {formatNumber(row.users_count)}
                </Link>
            </TableCell>
            <TableCell className="py-3 text-right">
                <Link
                    href={href}
                    className="inline-block text-muted-foreground hover:text-foreground"
                >
                    <ExternalLink className="h-3.5 w-3.5" />
                </Link>
            </TableCell>
        </TableRow>
    );
}

function OccurrencesCard({ summary }: { summary: Summary }) {
    const buckets = summary?.buckets ?? [];
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        Handled: b.handled,
        Unhandled: b.unhandled,
    }));

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center justify-between">
                    <span className="text-xs tracking-wider text-muted-foreground uppercase">
                        Occurrences
                    </span>
                    <div className="flex items-center gap-4 text-[10px] tracking-wider uppercase">
                        <Legend
                            color="bg-muted-foreground/40"
                            label="Handled"
                        />
                        <Legend color="bg-rose-500" label="Unhandled" />
                    </div>
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-baseline gap-8">
                    <div>
                        <div className="text-3xl font-semibold tabular-nums">
                            {formatNumber(summary.totals.total)}
                        </div>
                        <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                            Total
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="Handled"
                        value={formatNumber(summary.totals.handled)}
                        accent="text-foreground"
                    />
                    <Stat
                        label="Unhandled"
                        value={formatNumber(summary.totals.unhandled)}
                        accent="text-rose-600 dark:text-rose-400"
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
                            <Bar dataKey="Handled" stackId="s" fill="#a1a1aa" />
                            <Bar
                                dataKey="Unhandled"
                                stackId="s"
                                fill="#ef4444"
                                radius={[2, 2, 0, 0]}
                            />
                        </BarChart>
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

function HandledBadge({ handled }: { handled: boolean }) {
    if (handled) {
        return (
            <Badge
                variant="muted"
                className="font-mono text-[10px] tracking-wide uppercase"
            >
                Handled
            </Badge>
        );
    }

    return (
        <Badge
            variant="destructive"
            className="font-mono text-[10px] tracking-wide uppercase"
        >
            Unhandled
        </Badge>
    );
}

function CountBadge({
    value,
    active,
    accent,
}: {
    value: number;
    active: boolean;
    accent?: 'destructive';
}) {
    return (
        <span
            className={cn(
                'inline-flex h-5 min-w-5 items-center justify-center rounded-full px-1.5 text-[10px] font-semibold tabular-nums',
                active
                    ? accent === 'destructive'
                        ? 'bg-rose-500 text-white'
                        : 'bg-primary text-primary-foreground'
                    : 'bg-muted text-muted-foreground',
            )}
        >
            {value > 999 ? '999+' : value}
        </span>
    );
}

function Legend({ color, label }: { color: string; label: string }) {
    return (
        <span className="flex items-center gap-1.5 text-muted-foreground">
            <span className={cn('h-2 w-2 rounded-sm', color)} />
            {label}
        </span>
    );
}

function Stat({
    label,
    value,
    accent,
}: {
    label: string;
    value: string;
    accent: string;
}) {
    return (
        <div>
            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                {label}
            </div>
            <div className={cn('text-base font-semibold tabular-nums', accent)}>
                {value}
            </div>
        </div>
    );
}

function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatRelative(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);
    const diffMs = Date.now() - date.getTime();
    const seconds = Math.floor(diffMs / 1000);

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ago`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    return `${Math.floor(seconds / 86400)}d ago`;
}

const tooltipStyle = {
    background: 'var(--color-popover)',
    border: '1px solid var(--color-border)',
    borderRadius: 6,
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
} as const;
