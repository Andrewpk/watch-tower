import { router, usePage } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronRight,
    Circle,
    Clipboard,
    Folder,
    Radio,
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
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { AppLayout } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import exceptions from '@/routes/projects/exceptions';
import type { SharedProps } from '@/types/inertia';

type StackFrame = {
    file: string;
    source: string;
    code: Record<string, string> | null;
};

type Bucket = {
    bucket: string;
    handled: number;
    unhandled: number;
};

type OccurrenceListItem = {
    id: string;
    occurred_at: string | null;
    message: string | null;
    user_identifier: string | null;
    user_email: string | null;
    user_name: string | null;
    source_type: string;
    source_label: string | null;
};

type ExceptionDetail = {
    id: string;
    exception_class: string;
    short_class: string;
    first_message: string;
    first_file: string | null;
    first_line: number | null;
    total_count: number;
    status: string;
    is_handled: boolean;
    framework_version: string | null;
    language_version: string | null;
    first_seen: string | null;
    last_seen: string | null;
    php_versions: string[];
    laravel_versions: string[];
    impacted_users: number;
    servers: string[];
    first_release: string | null;
    environments: { environment: string; count: number }[];
    occurrences: { day: number; week: number; month: number };
    totals_in_range: { total: number; handled: number; unhandled: number };
    buckets: Bucket[];
    latest_occurrence: {
        id: string;
        message: string;
        file: string | null;
        line: number | null;
        stacktrace: StackFrame[];
        context: Record<string, unknown>;
        environment: string | null;
        occurred_at: string | null;
    } | null;
    occurrence_list: OccurrenceListItem[];
};

type EndUser = { id: string; email: string | null; count: number };

type Props = {
    exception: ExceptionDetail;
    users: EndUser[];
    selectedRange: string;
    filters: {
        user_id: string | null;
    };
};

export default function ExceptionShow({
    exception,
    users,
    selectedRange,
    filters,
}: Props) {
    const { props } = usePage<SharedProps>();
    const slug = props.currentProject?.slug ?? '';

    const issuesCount = 1;

    const onUserChange = (value: string) => {
        const url = new URL(window.location.href);

        if (value === '__all__') {
            url.searchParams.delete('user_id');
        } else {
            url.searchParams.set('user_id', value);
        }

        router.visit(url.pathname + url.search, { preserveScroll: true });
    };

    return (
        <AppLayout title={exception.first_message || exception.short_class}>
            <PageHeader
                title={
                    <span className="block max-w-3xl truncate text-xl leading-tight font-semibold">
                        {exception.first_message || exception.short_class}
                    </span>
                }
                breadcrumbs={[
                    { label: 'Activity' },
                    { label: 'Exceptions', href: exceptions.index(slug).url },
                ]}
                selectedRange={selectedRange}
                actions={
                    <div className="flex items-center gap-2">
                        <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2.5 py-1 text-xs text-muted-foreground">
                            <Radio className="h-3.5 w-3.5" />
                            {issuesCount} issue{issuesCount === 1 ? '' : 's'}
                        </span>
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
                    <DetailsCard exception={exception} />
                    <OccurrencesCard exception={exception} />
                </div>

                <OccurrenceSection exception={exception} />

                <OccurrencesTable exception={exception} />
            </div>
        </AppLayout>
    );
}

function DetailsCard({ exception }: { exception: ExceptionDetail }) {
    const phpVersions = exception.php_versions ?? [];
    const laravelVersions = exception.laravel_versions ?? [];
    const servers = exception.servers ?? [];
    const occurrences = exception.occurrences ?? { day: 0, week: 0, month: 0 };

    const rows: { label: string; value: React.ReactNode }[] = [
        {
            label: 'Last seen',
            value: (
                <span className="font-mono">
                    {formatAbsolute(exception.last_seen)}
                </span>
            ),
        },
        {
            label: 'First seen',
            value: (
                <span className="font-mono">
                    {formatAbsolute(exception.first_seen)}
                </span>
            ),
        },
        {
            label: 'First reported in',
            value: exception.first_release ? (
                <span className="font-mono break-all">
                    {exception.first_release}
                </span>
            ) : (
                <span className="text-muted-foreground">—</span>
            ),
        },
        {
            label: 'PHP versions',
            value: (
                <span className="font-mono">
                    {phpVersions.length ? phpVersions.join(', ') : '—'}
                </span>
            ),
        },
        {
            label: 'Laravel versions',
            value: (
                <span className="font-mono">
                    {laravelVersions.length ? laravelVersions.join(', ') : '—'}
                </span>
            ),
        },
        {
            label: 'Impacted users',
            value: (
                <span className="font-mono tabular-nums">
                    {exception.impacted_users}
                </span>
            ),
        },
        {
            label: 'Occurrences',
            value: (
                <div className="flex items-center gap-4 text-[11px] tracking-wider text-muted-foreground uppercase">
                    <OccurrenceChip label="30 days" value={occurrences.month} />
                    <OccurrenceChip label="7 days" value={occurrences.week} />
                    <OccurrenceChip label="24 hours" value={occurrences.day} />
                </div>
            ),
        },
        {
            label: 'Servers',
            value: (
                <span className="font-mono">
                    {servers.length ? servers.join(', ') : '—'}
                </span>
            ),
        },
    ];

    return (
        <Card>
            <CardContent className="p-6">
                <dl className="space-y-3.5 text-sm">
                    {rows.map((row) => (
                        <LeaderRow
                            key={row.label}
                            label={row.label}
                            value={row.value}
                        />
                    ))}
                </dl>
            </CardContent>
        </Card>
    );
}

function LeaderRow({
    label,
    value,
}: {
    label: string;
    value: React.ReactNode;
}) {
    return (
        <div className="flex items-baseline gap-3">
            <dt className="text-[11px] tracking-wider whitespace-nowrap text-muted-foreground uppercase">
                {label}
            </dt>
            <span
                aria-hidden
                className="flex-1 self-center border-b border-dotted border-border/80"
            />
            <dd className="text-right text-sm text-foreground">{value}</dd>
        </div>
    );
}

function OccurrenceChip({ label, value }: { label: string; value: number }) {
    return (
        <span className="flex items-center gap-1.5">
            <span className="text-muted-foreground">{label}</span>
            <span className="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs font-semibold text-foreground tabular-nums">
                {value}
            </span>
        </span>
    );
}

function OccurrencesCard({ exception }: { exception: ExceptionDetail }) {
    const buckets = exception.buckets ?? [];
    const data = buckets.map((b) => ({
        time: formatTime(b.bucket),
        Handled: b.handled,
        Unhandled: b.unhandled,
    }));

    const firstBucket = buckets[0]?.bucket;
    const lastBucket = buckets[buckets.length - 1]?.bucket;

    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center justify-between">
                    <span className="text-[11px] tracking-wider text-muted-foreground uppercase">
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
                <div className="text-3xl font-semibold tabular-nums">
                    {formatNumber(exception.totals_in_range?.total ?? 0)}
                </div>
                <div className="mt-3 h-40">
                    <ResponsiveContainer width="100%" height="100%">
                        <BarChart data={data}>
                            <CartesianGrid
                                stroke="var(--color-border)"
                                strokeDasharray="3 3"
                                vertical={false}
                            />
                            <XAxis
                                dataKey="time"
                                tick={false}
                                axisLine={false}
                                height={0}
                            />
                            <YAxis hide />
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
                <div className="mt-2 flex items-center justify-between font-mono text-[10px] text-muted-foreground">
                    <span>
                        {firstBucket ? formatAbsolute(firstBucket) : ''}
                    </span>
                    <span>{lastBucket ? formatAbsolute(lastBucket) : ''}</span>
                </div>
            </CardContent>
        </Card>
    );
}

type FrameGroup =
    | { type: 'frame'; frame: StackFrame; index: number }
    | { type: 'vendor'; frames: StackFrame[]; index: number }
    | { type: 'entry'; frame: StackFrame; index: number };

function groupFrames(frames: StackFrame[]): FrameGroup[] {
    const groups: FrameGroup[] = [];
    let buffer: StackFrame[] = [];

    const flushVendor = (atIndex: number) => {
        if (buffer.length === 0) {
            return;
        }

        groups.push({ type: 'vendor', frames: buffer, index: atIndex });
        buffer = [];
    };

    frames = frames || [];

    frames?.forEach((frame, i) => {
        if (i === frames.length - 1 && isEntrypoint(frame)) {
            flushVendor(i);
            groups.push({ type: 'entry', frame, index: i });

            return;
        }

        if (isVendor(frame) && !hasCode(frame)) {
            buffer.push(frame);

            return;
        }

        flushVendor(i);
        groups.push({ type: 'frame', frame, index: i });
    });

    flushVendor(frames.length);

    return groups;
}

function isVendor(frame: StackFrame): boolean {
    const path = parseFile(frame.file).path;

    return /^vendor\//.test(path) || /\/vendor\//.test(path);
}

function hasCode(frame: StackFrame): boolean {
    return !!frame.code && Object.keys(frame.code).length > 0;
}

function isEntrypoint(frame: StackFrame): boolean {
    const path = parseFile(frame.file).path;

    return /(^|\/)(artisan|public\/index\.php|server\.php)$/.test(path);
}

function parseFile(file: string | null | undefined): {
    path: string;
    line: number | null;
} {
    if (!file) {
        return { path: '', line: null };
    }

    const match = file.match(/^(.*):(\d+)$/);

    if (match) {
        return { path: match[1], line: Number(match[2]) };
    }

    return { path: file, line: null };
}

function OccurrenceSection({ exception }: { exception: ExceptionDetail }) {
    const occurrence = exception.latest_occurrence;
    const frames = useMemo(() => occurrence?.stacktrace ?? [], [occurrence]);
    const groups = useMemo(() => groupFrames(frames), [frames]);

    const [copied, setCopied] = useState(false);

    const copyMarkdown = async () => {
        const md = [
            `**${exception.short_class}**`,
            '',
            exception.first_message,
            '',
            '```',
            ...frames
                .slice(0, 10)
                .map(
                    (f) =>
                        `${f.file} — ${f.source} : ${f.code ? Object.values(f.code).join('\n') : 'No code snippet available'}`,
                ),
            '```',
        ].join('\n');

        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(md);
                setCopied(true);
                setTimeout(() => setCopied(false), 1500);
            } catch {
                fallbackCopy(md);
            }
        } else {
            fallbackCopy(md);
        }
    };

    const fallbackCopy = (text: string) => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed'; // avoid scroll jump
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();

        try {
            document.execCommand('copy');
            setCopied(true);
            setTimeout(() => setCopied(false), 1500);
        } catch (err) {
            console.error('Copy failed', err);
        }

        document.body.removeChild(textarea);
    };

    return (
        <Card className="overflow-hidden">
            <div className="flex items-start justify-between gap-4 border-b border-border px-5 py-4">
                <div className="min-w-0 flex-1">
                    <HandledBadge handled={exception.is_handled} />
                    <h2 className="mt-3 text-lg font-semibold tracking-tight">
                        {exception.short_class}
                    </h2>
                    <p className="mt-1 text-sm leading-relaxed text-muted-foreground">
                        {exception.first_message}
                    </p>
                </div>
                <div className="flex flex-shrink-0 items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={copyMarkdown}
                        className="gap-1.5"
                    >
                        <Clipboard className="h-3.5 w-3.5" />
                        {copied ? 'Copied!' : 'Copy as Markdown'}
                    </Button>
                    {exception.laravel_versions?.[0] && (
                        <VersionPill
                            label="Laravel"
                            value={exception.laravel_versions[0]}
                        />
                    )}
                    {exception.php_versions?.[0] && (
                        <VersionPill
                            label="PHP"
                            value={exception.php_versions[0]}
                        />
                    )}
                </div>
            </div>

            {frames.length > 0 ? (
                <div className="divide-y divide-border">
                    {groups.map((group, i) => {
                        if (group.type === 'frame') {
                            return (
                                <FrameRow
                                    key={`frame-${i}`}
                                    frame={group.frame}
                                    defaultOpen={i === 0}
                                />
                            );
                        }

                        if (group.type === 'vendor') {
                            return (
                                <VendorFrames
                                    key={`vendor-${i}`}
                                    frames={group.frames}
                                />
                            );
                        }

                        return (
                            <EntrypointFrame
                                key={`entry-${i}`}
                                frame={group.frame}
                            />
                        );
                    })}
                </div>
            ) : (
                <div className="px-5 py-10 text-center text-sm text-muted-foreground">
                    No stacktrace captured for the latest occurrence.
                </div>
            )}
        </Card>
    );
}

function FrameRow({
    frame,
    defaultOpen,
}: {
    frame: StackFrame;
    defaultOpen?: boolean;
}) {
    const [open, setOpen] = useState(!!defaultOpen);
    const { path, line } = parseFile(frame.file);
    const chain = buildChain(frame);
    const snippet = buildSnippet(frame);

    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-muted/40"
            >
                <Circle className="h-2 w-2 flex-shrink-0 fill-muted-foreground/60 text-muted-foreground/60" />
                <span
                    className="min-w-0 flex-1 truncate font-mono text-[12px] text-foreground"
                    title={chain}
                >
                    {chain}
                </span>
                <span
                    className="flex-shrink-0 truncate font-mono text-[11px] text-muted-foreground"
                    title={frame.file}
                >
                    {shortenFile(path)}
                    {line ? (
                        <span className="text-violet-500 dark:text-violet-400">
                            :{line}
                        </span>
                    ) : null}
                </span>
                <ChevronDown
                    className={cn(
                        'h-3.5 w-3.5 flex-shrink-0 text-muted-foreground transition-transform',
                        !open && '-rotate-90',
                    )}
                />
            </button>
            {open && snippet.length > 0 && (
                <pre className="overflow-x-auto bg-muted/20 px-4 py-3 font-mono text-xs leading-relaxed">
                    {snippet.map((entry) => (
                        <div
                            key={entry.line}
                            className={cn(
                                'grid grid-cols-[40px_1fr] items-baseline gap-3',
                                entry.highlighted &&
                                    'bg-destructive/15 text-foreground',
                            )}
                        >
                            <span className="text-right text-muted-foreground/60 select-none">
                                {entry.line}
                            </span>
                            <span className="whitespace-pre">{entry.text}</span>
                        </div>
                    ))}
                </pre>
            )}
        </div>
    );
}

function VendorFrames({ frames }: { frames: StackFrame[] }) {
    const [open, setOpen] = useState(false);

    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="flex w-full items-center gap-3 px-4 py-2.5 text-left hover:bg-muted/40"
            >
                <Folder className="h-3.5 w-3.5 flex-shrink-0 text-muted-foreground" />
                <span className="text-[12px] text-muted-foreground">
                    {frames.length} vendor frame{frames.length === 1 ? '' : 's'}
                </span>
                <span className="flex-1" />
                <ChevronRight
                    className={cn(
                        'h-3.5 w-3.5 flex-shrink-0 text-muted-foreground transition-transform',
                        open && 'rotate-90',
                    )}
                />
            </button>
            {open && (
                <ul className="divide-y divide-border/60 bg-muted/10">
                    {frames.map((frame, i) => {
                        const { path, line } = parseFile(frame.file);
                        const chain = buildChain(frame);

                        return (
                            <li
                                key={i}
                                className="flex items-center gap-3 px-4 py-2"
                            >
                                <span
                                    className="min-w-0 flex-1 truncate font-mono text-[11px] text-muted-foreground"
                                    title={chain}
                                >
                                    {chain}
                                </span>
                                <span
                                    className="shrink-0 truncate font-mono text-[11px] text-muted-foreground/80"
                                    title={frame.file}
                                >
                                    {shortenFile(path)}
                                    {line ? `:${line}` : ''}
                                </span>
                            </li>
                        );
                    })}
                </ul>
            )}
        </div>
    );
}

function EntrypointFrame({ frame }: { frame: StackFrame }) {
    const { path, line } = parseFile(frame.file);

    return (
        <div className="flex items-center gap-3 px-4 py-2.5">
            <Circle className="h-2 w-2 flex-shrink-0 fill-muted-foreground/60 text-muted-foreground/60" />
            <span className="text-[12px] text-muted-foreground">
                Entrypoint
            </span>
            <span className="flex-1" />
            <span className="font-mono text-[11px] text-muted-foreground">
                {shortenFile(path)}
                {line ? `:${line}` : ''}
            </span>
        </div>
    );
}

function OccurrencesTable({ exception }: { exception: ExceptionDetail }) {
    const list = exception.occurrence_list ?? [];

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2 px-1 text-sm">
                <span className="inline-flex h-5 w-5 items-center justify-center rounded-full">
                    <Circle className="h-3 w-3 fill-rose-500 text-rose-500" />
                </span>
                <span className="font-medium">
                    {list.length}{' '}
                    {list.length === 1 ? 'occurrence' : 'occurrences'}
                </span>
            </div>

            <Card>
                <div className="grid grid-cols-[180px_180px_1fr_120px] gap-4 border-b border-border px-5 py-2.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                    <span>Date</span>
                    <span>Source</span>
                    <span>Message</span>
                    <span className="text-right">User</span>
                </div>
                {list.length === 0 ? (
                    <div className="px-5 py-8 text-center text-sm text-muted-foreground">
                        No occurrences captured
                    </div>
                ) : (
                    <ul className="divide-y divide-border">
                        {list.map((row) => (
                            <li
                                key={row.id}
                                className="grid grid-cols-[180px_180px_1fr_120px] items-center gap-4 px-5 py-2.5 text-sm"
                            >
                                <span className="font-mono text-[12px] text-muted-foreground">
                                    {formatAbsoluteShort(row.occurred_at)}
                                </span>
                                <span
                                    className="flex min-w-0 items-center gap-2"
                                    title={`Triggered by ${row.source_type}${row.source_label ? ` ${row.source_label}` : ''}`}
                                >
                                    <SourceBadge type={row.source_type} />
                                    {row.source_label && (
                                        <span
                                            className="truncate font-mono text-[11px] text-muted-foreground"
                                            title={row.source_label}
                                        >
                                            {row.source_label}
                                        </span>
                                    )}
                                </span>
                                <span
                                    className="truncate text-[12px] text-foreground"
                                    title={row.message ?? ''}
                                >
                                    {row.message ?? '—'}
                                </span>
                                <span className="truncate text-right text-[12px] text-muted-foreground">
                                    {row.user_name ??
                                        row.user_email ??
                                        row.user_identifier ??
                                        'Guest'}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
            </Card>
        </div>
    );
}

function SourceBadge({ type }: { type: string }) {
    return (
        <span className="inline-flex items-center rounded border border-border bg-muted/40 px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
            {type}
        </span>
    );
}

type SnippetLine = { line: number; text: string; highlighted: boolean };

function buildSnippet(frame: StackFrame | undefined): SnippetLine[] {
    if (!frame || !frame.code) {
        return [];
    }

    const target = parseFile(frame.file).line;

    return Object.entries(frame.code)
        .map(([key, text]) => {
            const lineNumber = Number(key);

            return {
                line: lineNumber,
                text,
                highlighted: target !== null && lineNumber === target,
            };
        })
        .sort((a, b) => a.line - b.line);
}

function buildChain(frame: StackFrame): string {
    if (frame.source) {
        return frame.source;
    }

    return shortenFile(parseFile(frame.file).path);
}

function shortenFile(file: string | null | undefined): string {
    if (!file) {
        return '';
    }

    return file.replace(/^.*?\/(app|vendor)\//, '$1/');
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

function VersionPill({ label, value }: { label: string; value: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 rounded-md border border-border bg-card px-2.5 py-1 text-[11px]">
            <span className="tracking-wider text-muted-foreground uppercase">
                {label}
            </span>
            <span className="font-mono font-semibold text-foreground">
                {value}
            </span>
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

function formatNumber(value: number): string {
    return new Intl.NumberFormat().format(value);
}

function formatTime(iso: string): string {
    return new Date(iso).toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatAbsolute(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString([], {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
}

function formatAbsoluteShort(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleString([], {
        month: 'short',
        day: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    });
}

const tooltipStyle = {
    background: 'var(--color-popover)',
    border: '1px solid var(--color-border)',
    borderRadius: 6,
    color: 'var(--color-popover-foreground)',
    fontSize: 12,
} as const;
