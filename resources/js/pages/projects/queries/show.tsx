import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';
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
import { AppLayout } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import queriesRoutes from '@/routes/projects/queries';
import type { SharedProps } from '@/types/inertia';

type Bucket = {
    bucket: string;
    count: number;
    avg_duration: number | null;
    p95_duration: number | null;
};

type Detail = {
    sql: string;
    hash: string;
    connection: string | null;
    connection_types: string[];
    locations: {
        file: string;
        line: number | null;
        total: number;
        total_ms: number;
        avg_ms: number | null;
    }[];
    totals: {
        total: number;
        total_ms: number;
        min_ms: number | null;
        max_ms: number | null;
        avg_ms: number | null;
        p95_ms: number | null;
    };
    buckets: Bucket[];
    calls: {
        id: string;
        duration_ms: number;
        row_count: number | null;
        file: string | null;
        line: number | null;
        connection_type: string | null;
        is_slow: boolean;
        is_n_plus_one: boolean;
        occurred_at: string | null;
    }[];
};

type Props = {
    detail: Detail;
    selectedRange: string;
};

export default function QueryShow({ detail, selectedRange }: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    const condensed = detail.sql.replace(/\s+/g, ' ').trim();
    const truncated =
        condensed.length > 280
            ? condensed.slice(0, 280).trimEnd() + '…'
            : condensed;

    return (
        <AppLayout title="Query detail">
            <PageHeader
                title={
                    <span className="flex flex-col gap-1">
                        <span className="text-xs font-normal tracking-wider text-muted-foreground uppercase">
                            Queries
                        </span>
                        <span className="block max-w-[920px] font-mono text-base leading-snug font-medium break-words">
                            {truncated}
                        </span>
                    </span>
                }
                breadcrumbs={[
                    { label: 'Activity' },
                    { label: 'Queries', href: queriesRoutes.index(slug).url },
                    { label: 'Detail' },
                ]}
                selectedRange={selectedRange}
            />

            <div className="space-y-6 px-6 py-6">
                <div className="grid gap-6 lg:grid-cols-2">
                    <CallsCard detail={detail} />
                    <DurationCard detail={detail} />
                </div>

                <div className="grid gap-6 lg:grid-cols-2">
                    <InfoCard detail={detail} />
                    <SqlCard sql={detail.sql} connection={detail.connection} />
                </div>

                <LocationsCard locations={detail.locations} />
            </div>
        </AppLayout>
    );
}

function CallsCard({ detail }: { detail: Detail }) {
    const data = detail.buckets.map((b) => ({
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
                                dataKey="Calls"
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
                    <Stat
                        label="Avg"
                        value={formatMs(detail.totals.avg_ms)}
                        accent="text-foreground"
                    />
                    <Stat
                        label="P95"
                        value={formatMs(detail.totals.p95_ms)}
                        accent="text-foreground"
                    />
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

function InfoCard({ detail }: { detail: Detail }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>Info</CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-5">
                <dl className="space-y-3 text-sm">
                    <InfoRow
                        label="Total Time"
                        value={formatMs(detail.totals.total_ms)}
                    />
                    <InfoRow
                        label="Avg Time"
                        value={formatMs(detail.totals.avg_ms)}
                    />
                    <InfoRow
                        label="P95"
                        value={formatMs(detail.totals.p95_ms)}
                    />
                    <InfoRow
                        label="Calls"
                        value={formatNumber(detail.totals.total)}
                    />
                    {detail.connection ? (
                        <InfoRow
                            label="Connection"
                            value={detail.connection}
                            mono
                        />
                    ) : null}
                    {detail.connection_types.length > 0 ? (
                        <InfoRow
                            label="Connection type"
                            value={detail.connection_types.join(', ')}
                            mono
                        />
                    ) : null}
                </dl>

                {detail.calls.length > 0 ? (
                    <div className="mt-5 border-t border-border pt-4">
                        <div className="mb-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                            Recent calls
                        </div>
                        <ul className="space-y-1.5 text-xs">
                            {detail.calls.slice(0, 6).map((call) => (
                                <li
                                    key={call.id}
                                    className="flex items-center justify-between gap-3 font-mono"
                                >
                                    <span className="text-muted-foreground">
                                        {formatStamp(call.occurred_at)}
                                    </span>
                                    <span className="flex items-center gap-2">
                                        {call.is_slow ? (
                                            <Badge
                                                variant="warning"
                                                className="px-1.5 py-0 text-[9px]"
                                            >
                                                slow
                                            </Badge>
                                        ) : null}
                                        {call.is_n_plus_one ? (
                                            <Badge
                                                variant="destructive"
                                                className="px-1.5 py-0 text-[9px]"
                                            >
                                                N+1
                                            </Badge>
                                        ) : null}
                                        <span className="tabular-nums">
                                            {formatMs(call.duration_ms)}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    </div>
                ) : null}
            </CardContent>
        </Card>
    );
}

function LocationsCard({ locations }: { locations: Detail['locations'] }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle>Locations</CardTitle>
            </CardHeader>
            <Separator />
            <CardContent className="p-0">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Location</TableHead>
                            <TableHead className="text-right">Calls</TableHead>
                            <TableHead className="text-right">Total</TableHead>
                            <TableHead className="text-right">Avg</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {locations.length === 0 ? (
                            <TableRow>
                                <TableCell
                                    colSpan={4}
                                    className="py-6 text-center text-sm text-muted-foreground"
                                >
                                    No code location reported for this query
                                </TableCell>
                            </TableRow>
                        ) : (
                            locations.map((location) => (
                                <TableRow
                                    key={`${location.file}:${location.line}`}
                                >
                                    <TableCell className="py-2 font-mono text-xs">
                                        {location.line === null
                                            ? location.file
                                            : `${location.file}:${location.line}`}
                                    </TableCell>
                                    <TableCell className="py-2 text-right tabular-nums">
                                        {formatNumber(location.total)}
                                    </TableCell>
                                    <TableCell className="py-2 text-right text-muted-foreground tabular-nums">
                                        {formatMs(location.total_ms)}
                                    </TableCell>
                                    <TableCell className="py-2 text-right text-muted-foreground tabular-nums">
                                        {formatMs(location.avg_ms)}
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

function SqlCard({
    sql,
    connection,
}: {
    sql: string;
    connection: string | null;
}) {
    const formatted = useMemo(() => formatSql(sql), [sql]);

    return (
        <Card>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-3">
                <CardTitle>SQL</CardTitle>
                {connection ? (
                    <Badge variant="muted" className="font-mono text-[10px]">
                        {connection}
                    </Badge>
                ) : null}
            </CardHeader>
            <Separator />
            <CardContent className="p-0">
                <pre className="max-h-[460px] overflow-auto bg-muted/30 px-5 py-4 font-mono text-xs leading-relaxed">
                    <code>
                        {formatted.map((line, i) => (
                            <div
                                key={i}
                                className="grid grid-cols-[28px_1fr] gap-3"
                            >
                                <span className="text-right text-muted-foreground/60 select-none">
                                    {i + 1}
                                </span>
                                <span style={{ paddingLeft: line.indent * 12 }}>
                                    <SqlTokens text={line.text} />
                                </span>
                            </div>
                        ))}
                    </code>
                </pre>
            </CardContent>
        </Card>
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

function SqlTokens({ text }: { text: string }) {
    const tokens = text.split(/(\s+|`[^`]+`|'[^']*'|\?|\(|\)|,)/g);

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

                if (tok.startsWith("'") && tok.endsWith("'")) {
                    return (
                        <span
                            key={i}
                            className="text-emerald-600 dark:text-emerald-400"
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

type FormattedLine = { text: string; indent: number };

function formatSql(sql: string): FormattedLine[] {
    const condensed = sql.replace(/\s+/g, ' ').trim();
    const breakAt =
        /\b(select|from|where|and|or|inner join|left join|right join|outer join|join|group by|order by|having|limit|offset|union|values|set|insert into|update|delete from|returning|on)\b/gi;

    const lines: string[] = [];
    let buffer = '';

    for (const part of condensed.split(breakAt)) {
        if (part === undefined) {
            continue;
        }

        const trimmed = part.trim();

        if (trimmed === '') {
            continue;
        }

        if (
            breakAt.test(trimmed) ||
            /^(select|from|where|and|or|inner join|left join|right join|outer join|join|group by|order by|having|limit|offset|union|values|set|insert into|update|delete from|returning|on)$/i.test(
                trimmed,
            )
        ) {
            if (buffer.trim() !== '') {
                lines.push(buffer.trim());
            }

            buffer = trimmed;
        } else {
            buffer = buffer === '' ? trimmed : `${buffer} ${trimmed}`;
        }
    }

    if (buffer.trim() !== '') {
        lines.push(buffer.trim());
    }

    let indent = 0;

    return lines.map((line) => {
        const lower = line.toLowerCase();
        let useIndent = indent;

        if (/^(and|or|on)\b/.test(lower)) {
            useIndent = Math.max(1, indent);
        }

        if (/^\(/.test(line)) {
            indent++;
        }

        if (/^\)/.test(line)) {
            indent = Math.max(0, indent - 1);
            useIndent = indent;
        }

        return { text: line, indent: useIndent };
    });
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

function InfoRow({
    label,
    value,
    mono,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div className="flex items-center justify-between border-b border-dashed border-border/60 pb-2 last:border-b-0 last:pb-0">
            <dt className="text-[11px] tracking-wider text-muted-foreground uppercase">
                {label}
            </dt>
            <dd
                className={cn(
                    'font-medium text-foreground tabular-nums',
                    mono && 'font-mono text-xs',
                )}
            >
                {value}
            </dd>
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
