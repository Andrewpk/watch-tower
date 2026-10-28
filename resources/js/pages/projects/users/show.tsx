import { Link, usePage } from '@inertiajs/react';
import { Activity, Bug, FileText, Workflow } from 'lucide-react';
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
import exceptionsRoutes from '@/routes/projects/exceptions';
import jobsRoutes from '@/routes/projects/jobs';
import logsRoutes from '@/routes/projects/logs';
import requestsRoutes from '@/routes/projects/requests';
import usersRoutes from '@/routes/projects/users';
import type { SharedProps } from '@/types/inertia';

type RequestBucket = {
    bucket: string;
    success: number;
    client_error: number;
    server_error: number;
};

type UserDetail = {
    id: string;
    email: string | null;
    name: string | null;
    last_seen: string | null;
    summary: {
        total: number;
        success: number;
        client_error: number;
        server_error: number;
    };
    request_buckets: RequestBucket[];
    top_routes: { method: string; uri: string; count: number }[];
    slowest_routes: {
        method: string;
        uri: string;
        p95_ms: number | null;
        count: number;
    }[];
    top_jobs: { job_class: string; count: number }[];
    recent_requests: {
        id: string;
        method: string;
        uri: string;
        status_code: number | null;
        duration_ms: number | null;
        occurred_at: string | null;
    }[];
};

type Props = { user: UserDetail; selectedRange: string };

export default function UsersShow({ user, selectedRange }: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    return (
        <AppLayout title={user.name ?? user.id}>
            <PageHeader
                title={user.name ?? user.id}
                breadcrumbs={[
                    { label: 'Monitoring' },
                    { label: 'Users', href: usersRoutes.index(slug).url },
                    { label: user.name ?? user.email ?? user.id },
                ]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <InfoCard user={user} slug={slug} range={selectedRange} />
                    <RequestsCard user={user} />
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <RoutesCard title="Top Routes" rows={user.top_routes} />
                    <SlowestRoutesCard rows={user.slowest_routes} />
                    <TopJobsCard rows={user.top_jobs} />
                </div>

                <RecentRequestsCard user={user} />
            </div>
        </AppLayout>
    );
}

function InfoCard({
    user,
    slug,
    range,
}: {
    user: UserDetail;
    slug: string;
    range: string;
}) {
    const filterByUser = { query: { user_id: user.id, range } };
    const filterByName = { query: { user: user.name ?? '', range } };

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Info
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <dl className="space-y-3 text-sm">
                    <InfoRow
                        label="ID"
                        value={
                            <span className="font-mono text-xs">{user.id}</span>
                        }
                    />
                    <InfoRow label="Name" value={user.name ?? '—'} />
                    <InfoRow
                        label="Username"
                        value={
                            <span className="font-mono text-xs">
                                {user.email ?? '—'}
                            </span>
                        }
                    />
                    <InfoRow
                        label="Last seen"
                        value={formatLastSeen(user.last_seen)}
                    />
                </dl>

                <div className="mt-6 flex flex-wrap items-center gap-2">
                    <span className="text-[10px] tracking-wider text-muted-foreground uppercase">
                        Filter by
                    </span>
                    <FilterChip
                        icon={Activity}
                        label="Requests"
                        href={requestsRoutes.index(slug, filterByUser).url}
                    />
                    <FilterChip
                        icon={Workflow}
                        label="Jobs"
                        href={jobsRoutes.index(slug, { query: { range } }).url}
                    />
                    <FilterChip
                        icon={Bug}
                        label="Exceptions"
                        href={
                            exceptionsRoutes.index(slug, { query: { range } })
                                .url
                        }
                    />
                    <FilterChip
                        icon={FileText}
                        label="Logs"
                        href={logsRoutes.index(slug, filterByName).url}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

function InfoRow({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="flex items-baseline gap-3">
            <dt className="w-28 text-[10px] tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="flex-1 border-b border-dashed border-border/60 pb-1 text-right">
                {value}
            </dd>
        </div>
    );
}

function FilterChip({
    icon: Icon,
    label,
    href,
}: {
    icon: React.ComponentType<{ className?: string }>;
    label: string;
    href: string;
}) {
    return (
        <Link
            href={href}
            className="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted/40 px-2 py-1 text-xs text-muted-foreground transition-colors hover:border-foreground/30 hover:bg-muted hover:text-foreground"
        >
            <Icon className="h-3 w-3" />
            {label}
        </Link>
    );
}

function RequestsCard({ user }: { user: UserDetail }) {
    const data = user.request_buckets.map((b) => ({
        time: formatTime(b.bucket),
        '1/2/3XX': b.success,
        '4XX': b.client_error,
        '5XX': b.server_error,
    }));

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Requests
                </CardTitle>
                <div className="flex items-center gap-3 text-[10px] tracking-wider text-muted-foreground uppercase">
                    <span className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 rounded-full bg-slate-400" />
                        1/2/3XX
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        4XX
                    </span>
                    <span className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 rounded-full bg-rose-500" />
                        5XX
                    </span>
                </div>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <div className="flex items-baseline gap-8">
                    <div>
                        <div className="text-3xl font-semibold">
                            {user.summary.total.toLocaleString()}
                        </div>
                    </div>
                    <Separator orientation="vertical" className="h-10" />
                    <Stat
                        label="1/2/3XX"
                        value={user.summary.success.toLocaleString()}
                        accent="text-slate-700 dark:text-slate-300"
                    />
                    <Stat
                        label="4XX"
                        value={user.summary.client_error.toLocaleString()}
                        accent="text-amber-600 dark:text-amber-400"
                    />
                    <Stat
                        label="5XX"
                        value={user.summary.server_error.toLocaleString()}
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
                            <Bar dataKey="1/2/3XX" stackId="r" fill="#94a3b8" />
                            <Bar dataKey="4XX" stackId="r" fill="#f59e0b" />
                            <Bar
                                dataKey="5XX"
                                stackId="r"
                                fill="#f43f5e"
                                radius={[2, 2, 0, 0]}
                            />
                        </BarChart>
                    </ResponsiveContainer>
                </div>
            </CardContent>
        </Card>
    );
}

function RoutesCard({
    title,
    rows,
}: {
    title: string;
    rows: UserDetail['top_routes'];
}) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    {title}
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="space-y-1.5 p-4">
                {rows.length === 0 ? (
                    <div className="py-6 text-center text-xs text-muted-foreground">
                        No data
                    </div>
                ) : (
                    rows.map((row) => (
                        <div
                            key={`${row.method}|${row.uri}`}
                            className="flex items-center justify-between gap-3"
                        >
                            <div className="flex min-w-0 items-center gap-2">
                                <MethodBadge method={row.method} />
                                <span className="truncate font-mono text-xs">
                                    {row.uri}
                                </span>
                            </div>
                            <span className="font-mono text-xs text-muted-foreground">
                                {row.count}
                            </span>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function SlowestRoutesCard({ rows }: { rows: UserDetail['slowest_routes'] }) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Slowest Routes
                </CardTitle>
                <Badge variant="muted" className="text-[10px]">
                    P95
                </Badge>
            </CardHeader>
            <Separator />
            <CardContent className="space-y-1.5 p-4">
                {rows.length === 0 ? (
                    <div className="py-6 text-center text-xs text-muted-foreground">
                        No data
                    </div>
                ) : (
                    rows.map((row) => (
                        <div
                            key={`${row.method}|${row.uri}`}
                            className="flex items-center justify-between gap-3"
                        >
                            <div className="flex min-w-0 items-center gap-2">
                                <MethodBadge method={row.method} />
                                <span className="truncate font-mono text-xs">
                                    {row.uri}
                                </span>
                            </div>
                            <span className="font-mono text-xs text-muted-foreground">
                                {formatMs(row.p95_ms)}
                            </span>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function TopJobsCard({ rows }: { rows: UserDetail['top_jobs'] }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="text-xs tracking-wider text-muted-foreground uppercase">
                    Top Queued Jobs
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="space-y-1.5 p-4">
                {rows.length === 0 ? (
                    <div className="py-6 text-center text-xs text-muted-foreground">
                        No queued jobs
                    </div>
                ) : (
                    rows.map((row) => (
                        <div
                            key={row.job_class}
                            className="flex items-center justify-between gap-3"
                        >
                            <span className="truncate font-mono text-xs">
                                {row.job_class}
                            </span>
                            <span className="font-mono text-xs text-muted-foreground">
                                {row.count}
                            </span>
                        </div>
                    ))
                )}
            </CardContent>
        </Card>
    );
}

function RecentRequestsCard({ user }: { user: UserDetail }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2">
                    <Activity className="h-4 w-4 text-muted-foreground" />
                    <span>{user.summary.total.toLocaleString()} Requests</span>
                </CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead className="text-[10px] font-semibold tracking-wider uppercase">
                                Date
                            </TableHead>
                            <TableHead className="w-24 text-[10px] font-semibold tracking-wider uppercase">
                                Method
                            </TableHead>
                            <TableHead className="text-[10px] font-semibold tracking-wider uppercase">
                                URL
                            </TableHead>
                            <TableHead className="w-24 text-right text-[10px] font-semibold tracking-wider uppercase">
                                Status
                            </TableHead>
                            <TableHead className="w-28 text-right text-[10px] font-semibold tracking-wider uppercase">
                                Duration
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {user.recent_requests.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={5}
                                    className="py-10 text-center text-sm text-muted-foreground"
                                >
                                    No requests in window
                                </TableCell>
                            </TableRow>
                        ) : (
                            user.recent_requests.map((req) => (
                                <TableRow
                                    key={req.id}
                                    className="hover:bg-muted/50"
                                >
                                    <TableCell className="py-2 font-mono text-xs text-muted-foreground">
                                        {formatDate(req.occurred_at)}
                                    </TableCell>
                                    <TableCell className="py-2">
                                        <MethodBadge method={req.method} />
                                    </TableCell>
                                    <TableCell className="py-2">
                                        <span className="block max-w-[640px] truncate font-mono text-xs">
                                            {req.uri}
                                        </span>
                                    </TableCell>
                                    <TableCell className="py-2 text-right">
                                        <StatusBadge code={req.status_code} />
                                    </TableCell>
                                    <TableCell className="py-2 text-right font-mono text-xs text-muted-foreground">
                                        {formatMs(req.duration_ms)}
                                    </TableCell>
                                </TableRow>
                            ))
                        )}
                    </TableBody>
                </Table>
            </CardContent>
        </Card>
    );
}

function MethodBadge({ method }: { method: string }) {
    const m = method.toUpperCase();
    const color =
        m === 'GET' || m === 'HEAD'
            ? 'text-emerald-600 dark:text-emerald-400'
            : m === 'POST'
              ? 'text-sky-600 dark:text-sky-400'
              : m === 'PUT' || m === 'PATCH'
                ? 'text-amber-600 dark:text-amber-400'
                : m === 'DELETE'
                  ? 'text-rose-600 dark:text-rose-400'
                  : 'text-muted-foreground';

    return (
        <span
            className={cn(
                'font-mono text-[10px] font-semibold tracking-wider uppercase',
                color,
            )}
        >
            {m}
        </span>
    );
}

function StatusBadge({ code }: { code: number | null }) {
    if (code === null) {
        return (
            <span className="font-mono text-xs text-muted-foreground">—</span>
        );
    }

    const color =
        code >= 500
            ? 'text-rose-600 dark:text-rose-400'
            : code >= 400
              ? 'text-amber-600 dark:text-amber-400'
              : 'text-emerald-600 dark:text-emerald-400';

    return (
        <span className={cn('font-mono text-xs font-semibold', color)}>
            {code}
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
            <div className={cn('text-base font-semibold', accent)}>{value}</div>
        </div>
    );
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const d = new Date(iso);

    return `${d.toLocaleDateString()} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' })}`;
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
