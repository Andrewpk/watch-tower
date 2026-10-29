import { router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ChevronRight,
    Search,
    Users,
    X,
} from 'lucide-react';
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

import { ModelCounts } from '@/components/model-counts';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { AppLayout } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

type Bucket = {
    bucket: string;
    success: number;
    client_error: number;
    server_error: number;
    avg_duration: number | null;
    p95_duration: number | null;
};

type Summary = {
    totals: {
        total: number;
        success: number;
        client_error: number;
        server_error: number;
    };
    duration: {
        min_ms: number | null;
        max_ms: number | null;
        avg_ms: number | null;
        p95_ms: number | null;
    };
    buckets: Bucket[];
};

type RouteRow = {
    method: string;
    uri: string;
    success: number;
    client_error: number;
    server_error: number;
    total: number;
    avg_ms: number | null;
    p95_ms: number | null;
};

type RouteDetail = {
    method: string;
    uri: string;
    totals: Summary['totals'];
    duration: { avg_ms: number | null; p95_ms: number | null };
    buckets: Bucket[];
    recent: Array<{
        id: string;
        lazy_loads: number | null;
        hydrated_models: number | null;
        status_code: number | null;
        duration_ms: number | null;
        occurred_at: string | null;
        user_identifier: string | null;
    }>;
};

type EndUser = { id: string; email: string | null; count: number };

type Props = {
    summary: Summary;
    routes: RouteRow[];
    users: EndUser[];
    routeDetail: RouteDetail | null;
    selectedRange: string;
    filters: {
        user_id: string | null;
        search: string | null;
        route_method: string | null;
        route_uri: string | null;
    };
};

type SortKey =
    | 'method'
    | 'uri'
    | 'success'
    | 'client_error'
    | 'server_error'
    | 'total'
    | 'avg_ms'
    | 'p95_ms';
type SortDir = 'asc' | 'desc';

export default function RequestsIndex({
    summary,
    routes,
    users,
    routeDetail,
    selectedRange,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [sortKey, setSortKey] = useState<SortKey>('total');
    const [sortDir, setSortDir] = useState<SortDir>('desc');

    const sortedRoutes = useMemo(() => {
        const copy = [...routes];
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
    }, [routes, sortKey, sortDir]);

    const toggleSort = (key: SortKey) => {
        if (sortKey === key) {
            setSortDir(sortDir === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            setSortDir(key === 'method' || key === 'uri' ? 'asc' : 'desc');
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

    const onSelectRoute = (row: RouteRow) => {
        updateQuery({ route_method: row.method, route_uri: row.uri });
    };

    const onCloseDetail = () => {
        updateQuery({ route_method: null, route_uri: null });
    };

    const onUserChange = (value: string) => {
        updateQuery({ user_id: value === '__all__' ? null : value });
    };

    return (
        <AppLayout title="Requests">
            <PageHeader
                title="Requests"
                breadcrumbs={[{ label: 'Activity' }, { label: 'Requests' }]}
                selectedRange={selectedRange}
                actions={
                    <div className="flex items-center gap-2">
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
                                <SelectItem value="__all__">
                                    All Users
                                </SelectItem>
                                {users.map((user) => (
                                    <SelectItem key={user.id} value={user.id}>
                                        {user.email ?? user.id} ({user.count})
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                }
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <RequestsCard summary={summary} />
                    <DurationCard summary={summary} />
                </div>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                        <CardTitle>Routes</CardTitle>
                        <form
                            onSubmit={onSearchSubmit}
                            className="relative w-64"
                        >
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search routes"
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
                                        label="METHOD"
                                        sortKey="method"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        className="w-44"
                                    />
                                    <SortableHead
                                        label="PATH"
                                        sortKey="uri"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                    />
                                    <SortableHead
                                        label="1/2/3XX"
                                        sortKey="success"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-24"
                                    />
                                    <SortableHead
                                        label="4XX"
                                        sortKey="client_error"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="5XX"
                                        sortKey="server_error"
                                        current={sortKey}
                                        dir={sortDir}
                                        onSort={toggleSort}
                                        align="right"
                                        className="w-20"
                                    />
                                    <SortableHead
                                        label="TOTAL"
                                        sortKey="total"
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
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {sortedRoutes.length === 0 ? (
                                    <TableRow>
                                        <TableCell
                                            colSpan={8}
                                            className="py-10 text-center text-sm text-muted-foreground"
                                        >
                                            No requests captured in{' '}
                                            {selectedRange.toUpperCase()}
                                        </TableCell>
                                    </TableRow>
                                ) : (
                                    sortedRoutes.map((row) => {
                                        const isActive =
                                            routeDetail?.method ===
                                                row.method &&
                                            routeDetail?.uri === row.uri;

                                        return (
                                            <TableRow
                                                key={`${row.method}|${row.uri}`}
                                                onClick={() =>
                                                    onSelectRoute(row)
                                                }
                                                className={cn(
                                                    'cursor-pointer hover:bg-muted/50',
                                                    isActive && 'bg-muted/60',
                                                )}
                                            >
                                                <TableCell className="py-2.5">
                                                    <MethodBadge
                                                        method={row.method}
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2.5 font-mono text-xs">
                                                    <div className="flex items-center gap-1.5">
                                                        <span className="truncate">
                                                            {row.uri}
                                                        </span>
                                                        {isActive ? (
                                                            <ChevronRight className="h-3.5 w-3.5 text-muted-foreground" />
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                <TableCell className="py-2.5 text-right font-mono text-xs text-emerald-600 dark:text-emerald-400">
                                                    {formatNumber(row.success)}
                                                </TableCell>
                                                <TableCell className="py-2.5 text-right font-mono text-xs text-amber-600 dark:text-amber-400">
                                                    {formatNumber(
                                                        row.client_error,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-2.5 text-right font-mono text-xs text-rose-600 dark:text-rose-400">
                                                    {formatNumber(
                                                        row.server_error,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-2.5 text-right font-mono text-xs">
                                                    {formatNumber(row.total)}
                                                </TableCell>
                                                <TableCell className="py-2.5 text-right font-mono text-xs text-muted-foreground">
                                                    {formatMs(row.avg_ms)}
                                                </TableCell>
                                                <TableCell className="py-2.5 text-right font-mono text-xs text-muted-foreground">
                                                    {formatMs(row.p95_ms)}
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            {routeDetail ? (
                <RouteDetailPanel
                    detail={routeDetail}
                    onClose={onCloseDetail}
                />
            ) : null}
        </AppLayout>
    );
}

function RequestsCard({ summary }: { summary: Summary }) {
    const { totals, buckets } = summary;
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        '1/2/3XX': b.success,
        '4XX': b.client_error,
        '5XX': b.server_error,
    }));

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Requests
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
                            Total
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="1/2/3XX"
                        value={formatNumber(totals.success)}
                        accent="text-emerald-600 dark:text-emerald-400"
                    />
                    <Stat
                        label="4XX"
                        value={formatNumber(totals.client_error)}
                        accent="text-amber-600 dark:text-amber-400"
                    />
                    <Stat
                        label="5XX"
                        value={formatNumber(totals.server_error)}
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
                            <Bar
                                dataKey="1/2/3XX"
                                stackId="s"
                                fill="#10b981"
                                radius={[0, 0, 0, 0]}
                            />
                            <Bar
                                dataKey="4XX"
                                stackId="s"
                                fill="#f59e0b"
                                radius={[0, 0, 0, 0]}
                            />
                            <Bar
                                dataKey="5XX"
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

function DurationCard({ summary }: { summary: Summary }) {
    const { duration, buckets } = summary;
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
                        label="Avg"
                        value={formatMs(duration.avg_ms)}
                        accent="text-indigo-600 dark:text-indigo-400"
                    />
                    <Stat
                        label="P95"
                        value={formatMs(duration.p95_ms)}
                        accent="text-violet-600 dark:text-violet-400"
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
                                stroke="#8b5cf6"
                                strokeWidth={2}
                                strokeDasharray="4 2"
                                dot={false}
                            />
                        </LineChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}

function RouteDetailPanel({
    detail,
    onClose,
}: {
    detail: RouteDetail;
    onClose: () => void;
}) {
    const reqData = detail.buckets.map((b) => ({
        time: formatTime(b.bucket),
        '1/2/3XX': b.success,
        '4XX': b.client_error,
        '5XX': b.server_error,
    }));

    const durData = detail.buckets.map((b) => ({
        time: formatTime(b.bucket),
        avg: b.avg_duration ?? 0,
        p95: b.p95_duration ?? 0,
    }));

    return (
        <>
            <div
                className="fixed inset-0 z-40 bg-background/50 backdrop-blur-sm"
                onClick={onClose}
            />
            <aside className="fixed inset-y-0 right-0 z-50 flex w-full max-w-2xl flex-col border-l border-border bg-background shadow-xl">
                <div className="flex items-start justify-between border-b border-border px-6 py-4">
                    <div className="flex items-start gap-3">
                        <MethodBadge method={detail.method} />
                        <div className="font-mono text-sm">{detail.uri}</div>
                    </div>
                    <Button variant="ghost" size="icon" onClick={onClose}>
                        <X className="h-4 w-4" />
                    </Button>
                </div>

                <div className="flex-1 space-y-6 overflow-y-auto px-6 py-6">
                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                                Requests
                            </CardTitle>
                        </CardHeader>
                        <Separator />
                        <CardContent className="p-4">
                            <div className="flex items-baseline gap-4">
                                <div className="text-2xl font-semibold">
                                    {formatNumber(detail.totals.total)}
                                </div>
                                <Stat
                                    label="1/2/3XX"
                                    value={formatNumber(detail.totals.success)}
                                    accent="text-emerald-600 dark:text-emerald-400"
                                />
                                <Stat
                                    label="4XX"
                                    value={formatNumber(
                                        detail.totals.client_error,
                                    )}
                                    accent="text-amber-600 dark:text-amber-400"
                                />
                                <Stat
                                    label="5XX"
                                    value={formatNumber(
                                        detail.totals.server_error,
                                    )}
                                    accent="text-rose-600 dark:text-rose-400"
                                />
                            </div>
                            <div className="mt-4 h-32">
                                <ResponsiveContainer width="100%" height="100%">
                                    <BarChart data={reqData}>
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
                                                Math.floor(reqData.length / 6),
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
                                            cursor={{
                                                fill: 'var(--color-muted)',
                                            }}
                                            contentStyle={tooltipStyle}
                                        />
                                        <Bar
                                            dataKey="1/2/3XX"
                                            stackId="s"
                                            fill="#10b981"
                                        />
                                        <Bar
                                            dataKey="4XX"
                                            stackId="s"
                                            fill="#f59e0b"
                                        />
                                        <Bar
                                            dataKey="5XX"
                                            stackId="s"
                                            fill="#ef4444"
                                            radius={[2, 2, 0, 0]}
                                        />
                                    </BarChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                                Duration
                            </CardTitle>
                        </CardHeader>
                        <Separator />
                        <CardContent className="p-4">
                            <div className="flex items-baseline gap-6">
                                <Stat
                                    label="Avg"
                                    value={formatMs(detail.duration.avg_ms)}
                                    accent="text-indigo-600 dark:text-indigo-400"
                                />
                                <Stat
                                    label="P95"
                                    value={formatMs(detail.duration.p95_ms)}
                                    accent="text-violet-600 dark:text-violet-400"
                                />
                            </div>
                            <div className="mt-4 h-32">
                                <ResponsiveContainer width="100%" height="100%">
                                    <LineChart data={durData}>
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
                                                Math.floor(durData.length / 6),
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
                                        />
                                        <Line
                                            type="monotone"
                                            dataKey="p95"
                                            stroke="#8b5cf6"
                                            strokeWidth={2}
                                            strokeDasharray="4 2"
                                            dot={false}
                                        />
                                    </LineChart>
                                </ResponsiveContainer>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader className="pb-3">
                            <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                                Recent Requests
                            </CardTitle>
                        </CardHeader>
                        <Separator />
                        <CardContent className="p-0">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-20">
                                            Status
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Duration
                                        </TableHead>
                                        <TableHead>User</TableHead>
                                        <TableHead className="text-right">
                                            Lazy / Models
                                        </TableHead>
                                        <TableHead>When</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {detail.recent.length === 0 ? (
                                        <TableRow>
                                            <TableCell
                                                colSpan={5}
                                                className="py-6 text-center text-sm text-muted-foreground"
                                            >
                                                No recent requests
                                            </TableCell>
                                        </TableRow>
                                    ) : (
                                        detail.recent.map((trace) => (
                                            <TableRow key={trace.id}>
                                                <TableCell className="py-2">
                                                    <StatusBadge
                                                        status={
                                                            trace.status_code
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2 text-right font-mono text-xs">
                                                    {formatMs(
                                                        trace.duration_ms,
                                                    )}
                                                </TableCell>
                                                <TableCell className="py-2 font-mono text-xs text-muted-foreground">
                                                    {trace.user_identifier ??
                                                        '—'}
                                                </TableCell>
                                                <TableCell className="py-2 text-right font-mono text-xs">
                                                    <ModelCounts
                                                        lazyLoads={
                                                            trace.lazy_loads
                                                        }
                                                        hydratedModels={
                                                            trace.hydrated_models
                                                        }
                                                    />
                                                </TableCell>
                                                <TableCell className="py-2 text-xs text-muted-foreground">
                                                    {formatRelative(
                                                        trace.occurred_at,
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                </div>
            </aside>
        </>
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

function MethodBadge({ method }: { method: string }) {
    const m = method.toUpperCase();
    const color =
        m === 'GET' || m === 'HEAD'
            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300'
            : m === 'POST'
              ? 'bg-rose-500/15 text-rose-700 dark:text-rose-300'
              : m === 'PUT' || m === 'PATCH'
                ? 'bg-amber-500/15 text-amber-700 dark:text-amber-300'
                : m === 'DELETE'
                  ? 'bg-red-500/15 text-red-700 dark:text-red-300'
                  : 'bg-slate-500/15 text-slate-700 dark:text-slate-300';

    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-2 py-0.5 font-mono text-[11px] font-semibold',
                color,
            )}
        >
            {m}
        </span>
    );
}

function StatusBadge({ status }: { status: number | null }) {
    if (status === null) {
        return <span className="text-xs text-muted-foreground">—</span>;
    }

    let variant: 'success' | 'warning' | 'destructive' | 'secondary' =
        'success';

    if (status >= 500) {
        variant = 'destructive';
    } else if (status >= 400) {
        variant = 'warning';
    } else if (status >= 300) {
        variant = 'secondary';
    }

    return (
        <Badge variant={variant} className="font-mono">
            {status}
        </Badge>
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
            <div className={cn('text-base font-semibold', accent)}>{value}</div>
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
