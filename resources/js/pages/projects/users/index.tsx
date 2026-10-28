import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Search,
    TriangleAlert,
    Users as UsersIcon,
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
import usersRoutes from '@/routes/projects/users';
import type { SharedProps } from '@/types/inertia';

type UserBucket = { bucket: string; count: number };
type RequestBucket = { bucket: string; authenticated: number; guest: number };

type Summary = {
    authenticated_users: number;
    total_requests: number;
    authenticated_requests: number;
    guest_requests: number;
    user_buckets: UserBucket[];
    request_buckets: RequestBucket[];
};

type UserRow = {
    id: string;
    email: string | null;
    name: string | null;
    requests_total: number;
    requests_2xx: number;
    requests_4xx: number;
    requests_5xx: number;
    queued_jobs: number;
    exceptions: number;
    last_seen: string | null;
};

type Props = {
    summary: Summary;
    users: UserRow[];
    selectedRange: string;
    filters: { search: string | null };
};

type SortKey =
    | 'requests_total'
    | 'queued_jobs'
    | 'exceptions'
    | 'requests_2xx'
    | 'requests_4xx'
    | 'requests_5xx'
    | 'last_seen'
    | 'name';
type SortDir = 'asc' | 'desc';

export default function UsersIndex({
    summary,
    users,
    selectedRange,
    filters,
}: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    const [search, setSearch] = useState(filters.search ?? '');
    const [sortKey, setSortKey] = useState<SortKey>('requests_total');
    const [sortDir, setSortDir] = useState<SortDir>('desc');

    const sorted = useMemo(() => {
        const copy = [...users];
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

            const as = String(av);
            const bs = String(bv);

            return sortDir === 'asc'
                ? as.localeCompare(bs)
                : bs.localeCompare(as);
        });

        return copy;
    }, [users, sortKey, sortDir]);

    const toggleSort = (key: SortKey) => {
        if (sortKey === key) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortDir(key === 'name' ? 'asc' : 'desc');
        }
    };

    const onSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        const url = new URL(window.location.href);
        const value = search.trim();

        if (value) {
            url.searchParams.set('search', value);
        } else {
            url.searchParams.delete('search');
        }

        router.visit(url.pathname + url.search, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <AppLayout title="Users">
            <PageHeader
                title="Users"
                breadcrumbs={[{ label: 'Monitoring' }, { label: 'Users' }]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <AuthenticatedUsersCard summary={summary} />
                    <RequestsBreakdownCard summary={summary} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle className="flex items-center gap-2">
                            <UsersIcon className="h-4 w-4 text-muted-foreground" />
                            <span>{sorted.length.toLocaleString()} Users</span>
                        </CardTitle>
                        <form
                            onSubmit={onSearchSubmit}
                            className="relative w-64"
                        >
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search users"
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
                                        label="USER"
                                        sortKey="name"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                    />
                                    <SortableHead
                                        label="1/2/3XX"
                                        sortKey="requests_2xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="4XX"
                                        sortKey="requests_4xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="5XX"
                                        sortKey="requests_5xx"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="REQUESTS"
                                        sortKey="requests_total"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-28"
                                    />
                                    <SortableHead
                                        label="QUEUED JOBS"
                                        sortKey="queued_jobs"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-32"
                                    />
                                    <SortableHead
                                        label="EXCEPTIONS"
                                        sortKey="exceptions"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-28"
                                    />
                                    <SortableHead
                                        label="LAST SEEN"
                                        sortKey="last_seen"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-28"
                                    />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sorted.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={8}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No authenticated users in{' '}
                                            {selectedRange.toUpperCase()}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    sorted.map((row) => (
                                        <UserRowTr
                                            key={row.id}
                                            row={row}
                                            slug={slug}
                                            range={selectedRange}
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

function UserRowTr({
    row,
    slug,
    range,
}: {
    row: UserRow;
    slug: string;
    range: string;
}) {
    const href = usersRoutes.show([slug, row.id], { query: { range } }).url;

    return (
        <TableRow className="cursor-pointer hover:bg-muted/50">
            <TableCell className="py-2.5">
                <Link
                    href={href}
                    className="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 hover:text-foreground"
                >
                    <span className="text-sm font-medium text-foreground">
                        {row.name ?? '—'}
                    </span>
                    <span className="font-mono text-xs text-muted-foreground">
                        {row.email ?? row.id}
                    </span>
                </Link>
            </TableCell>
            <NumericCell
                value={row.requests_2xx}
                muted={row.requests_2xx === 0}
            />
            <NumericCell
                value={row.requests_4xx}
                muted={row.requests_4xx === 0}
                prefix={
                    row.requests_4xx > 0 ? (
                        <TriangleAlert className="mr-1 inline-block h-3.5 w-3.5 text-amber-500" />
                    ) : null
                }
                color={
                    row.requests_4xx > 0
                        ? 'text-amber-600 dark:text-amber-400'
                        : undefined
                }
            />
            <NumericCell
                value={row.requests_5xx}
                muted={row.requests_5xx === 0}
                prefix={
                    row.requests_5xx > 0 ? (
                        <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-rose-500" />
                    ) : null
                }
                color={
                    row.requests_5xx > 0
                        ? 'text-rose-600 dark:text-rose-400'
                        : undefined
                }
            />
            <NumericCell value={row.requests_total} bold />
            <NumericCell
                value={row.queued_jobs}
                muted={row.queued_jobs === 0}
            />
            <NumericCell
                value={row.exceptions}
                muted={row.exceptions === 0}
                prefix={
                    row.exceptions > 0 ? (
                        <AlertCircle className="mr-1 inline-block h-3.5 w-3.5 text-rose-500" />
                    ) : null
                }
                color={
                    row.exceptions > 0
                        ? 'text-rose-600 dark:text-rose-400'
                        : undefined
                }
            />
            <TableCell className="py-2.5 text-right font-mono text-xs text-muted-foreground">
                {formatLastSeen(row.last_seen)}
            </TableCell>
        </TableRow>
    );
}

function NumericCell({
    value,
    muted,
    bold,
    prefix,
    color,
}: {
    value: number;
    muted?: boolean;
    bold?: boolean;
    prefix?: React.ReactNode;
    color?: string;
}) {
    return (
        <TableCell
            className={cn(
                'py-2.5 text-right font-mono text-xs',
                muted && 'text-muted-foreground/60',
                bold && 'font-semibold',
                color,
            )}
        >
            {prefix}
            {value}
        </TableCell>
    );
}

function AuthenticatedUsersCard({ summary }: { summary: Summary }) {
    const data = summary.user_buckets.map((b) => ({
        time: formatTime(b.bucket),
        Users: b.count,
    }));

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Authenticated Users
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div>
                    <div className="text-3xl font-semibold">
                        {formatCompact(summary.authenticated_users)}
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
                                dataKey="Users"
                                fill="#10b981"
                                radius={[2, 2, 0, 0]}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}

function RequestsBreakdownCard({ summary }: { summary: Summary }) {
    const data = summary.request_buckets.map((b) => ({
        time: formatTime(b.bucket),
        Authenticated: b.authenticated,
        Guest: b.guest,
    }));

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Requests
                </CardTitle>
                <div className="flex items-center gap-3 text-[10px] tracking-wider text-muted-foreground uppercase">
                    <span className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" />
                        Authenticated
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        Guest
                    </span>
                </div>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-baseline gap-8">
                    <div>
                        <div className="text-3xl font-semibold">
                            {formatCompact(summary.total_requests)}
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="Authenticated"
                        value={formatCompact(summary.authenticated_requests)}
                        dot="bg-emerald-500"
                        accent="text-emerald-600 dark:text-emerald-400"
                    />
                    <Stat
                        label="Guest"
                        value={formatCompact(summary.guest_requests)}
                        dot="bg-amber-500"
                        accent="text-amber-600 dark:text-amber-400"
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
                                dataKey="Authenticated"
                                stackId="r"
                                fill="#10b981"
                                radius={[0, 0, 0, 0]}
                            />
                            <Bar
                                dataKey="Guest"
                                stackId="r"
                                fill="#f59e0b"
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

function formatLastSeen(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);
    const diff = Date.now() - date.getTime();
    const seconds = Math.floor(diff / 1000);

    if (seconds < 30) {
        return 'just now';
    }

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    const minutes = Math.floor(seconds / 60);

    if (minutes < 60) {
        return `${minutes}m ago`;
    }

    const hours = Math.floor(minutes / 60);

    if (hours < 24) {
        return `${hours}h ago`;
    }

    const days = Math.floor(hours / 24);

    return `${days}d ago`;
}

const tooltipStyle = {
    background: 'var(--color-popover)',
    border: '1px solid var(--color-border)',
    borderRadius: 6,
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
} as const;
