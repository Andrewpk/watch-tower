import { router } from '@inertiajs/react';
import {
    ChevronDown,
    ChevronUp,
    ExternalLink,
    Search,
    User,
    X,
} from 'lucide-react';
import { useState } from 'react';

import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
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
import type { Paginated } from '@/types/pagination';

type Log = {
    id: string;
    level: string;
    message: string;
    source_type: string | null;
    source_label: string | null;
    user_name: string | null;
    context: Record<string, unknown> | null;
    occurred_at: string | null;
};

type Props = {
    logs: Paginated<Log>;
    users: string[];
    levels: string[];
    selectedRange: string;
    filters: {
        search: string | null;
        level: string | null;
        user: string | null;
    };
};

export default function LogsIndex({
    logs,
    users,
    levels,
    selectedRange,
    filters,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [selected, setSelected] = useState<Log | null>(null);

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

    const onSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        visit({ search: search || null, page: null });
    };

    return (
        <AppLayout title="Logs">
            <PageHeader
                title="Logs"
                breadcrumbs={[{ label: 'Monitoring' }, { label: 'Logs' }]}
                selectedRange={selectedRange}
                actions={
                    <div className="flex items-center gap-2">
                        <form
                            onSubmit={onSearchSubmit}
                            className="relative w-64"
                        >
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search logs"
                                className="h-8 pl-8 text-xs"
                            />
                        </form>
                        <div className="flex items-center gap-1.5">
                            <span className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                Level
                            </span>
                            <Select
                                value={filters.level ?? 'all'}
                                onValueChange={(value) =>
                                    visit({
                                        level: value === 'all' ? null : value,
                                        page: null,
                                    })
                                }
                            >
                                <SelectTrigger className="h-8 w-28 text-xs">
                                    <SelectValue placeholder="All" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {levels.map((lvl) => (
                                        <SelectItem
                                            key={lvl}
                                            value={lvl}
                                            className="capitalize"
                                        >
                                            {lvl}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <Select
                            value={filters.user ?? 'all'}
                            onValueChange={(value) =>
                                visit({
                                    user: value === 'all' ? null : value,
                                    page: null,
                                })
                            }
                        >
                            <SelectTrigger className="h-8 w-36 text-xs">
                                <User className="mr-1.5 h-3.5 w-3.5" />
                                <SelectValue placeholder="All Users" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Users</SelectItem>
                                {users.map((u) => (
                                    <SelectItem key={u} value={u}>
                                        {u}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                }
            />

            <div className="flex flex-1 flex-col overflow-y-auto px-6 py-6">
                <div className="overflow-hidden rounded-md border border-border bg-card">
                    {logs.data.length === 0 ? (
                        <div className="py-16 text-center text-sm text-muted-foreground">
                            No logs in {selectedRange.toUpperCase()}
                        </div>
                    ) : (
                        <ul className="divide-y divide-border">
                            {logs.data.map((log) => (
                                <LogRow
                                    key={log.id}
                                    log={log}
                                    active={selected?.id === log.id}
                                    onClick={() => setSelected(log)}
                                />
                            ))}
                        </ul>
                    )}
                    <Pagination
                        links={logs.links}
                        from={logs.from}
                        to={logs.to}
                        total={logs.total}
                    />
                </div>
            </div>

            {selected ? (
                <DetailPanel log={selected} onClose={() => setSelected(null)} />
            ) : null}
        </AppLayout>
    );
}

function LogRow({
    log,
    active,
    onClick,
}: {
    log: Log;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <li>
            <button
                type="button"
                onClick={onClick}
                className={cn(
                    'flex w-full items-center gap-3 px-4 py-2.5 text-left font-mono text-xs transition-colors hover:bg-muted/40',
                    active && 'bg-muted/60',
                )}
            >
                <span className="w-44 shrink-0 text-muted-foreground">
                    {formatStamp(log.occurred_at)}
                </span>
                {log.source_type ? (
                    <Badge
                        variant="muted"
                        className="font-mono text-[10px] uppercase"
                    >
                        {log.source_type}
                    </Badge>
                ) : (
                    <span className="w-16 shrink-0" />
                )}
                <span
                    className="w-40 shrink-0 truncate text-muted-foreground"
                    title={log.source_label ?? ''}
                >
                    {log.source_label ?? '—'}
                </span>
                <LevelBadge level={log.level} />
                <span className="flex-1 truncate" title={log.message}>
                    {log.message}
                </span>
                <span
                    className={cn(
                        'h-1.5 w-1.5 shrink-0 rounded-full',
                        active ? levelDotColor(log.level) : 'bg-transparent',
                    )}
                />
            </button>
        </li>
    );
}

function DetailPanel({ log, onClose }: { log: Log; onClose: () => void }) {
    return (
        <>
            <div
                className="fixed inset-0 z-40 bg-black/30 backdrop-blur-[1px]"
                onClick={onClose}
                aria-hidden="true"
            />
            <aside className="fixed top-0 right-0 bottom-0 z-50 flex w-full max-w-[440px] flex-col overflow-y-auto border-l border-border bg-card shadow-2xl">
                <div className="flex items-center gap-2 border-b border-border px-4 py-3">
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="h-7 w-7"
                        onClick={onClose}
                        aria-label="Close"
                    >
                        <X className="h-4 w-4" />
                    </Button>
                    <span className="font-mono text-xs text-muted-foreground">
                        {formatStampFull(log.occurred_at)}
                    </span>
                </div>

                <div className="space-y-5 px-5 py-5">
                    <div>
                        <div className="flex items-start gap-2">
                            <LevelBadge level={log.level} />
                            <p className="text-sm leading-snug break-words">
                                {log.message}
                            </p>
                        </div>
                    </div>

                    <Separator />

                    <div>
                        <div className="mb-2 flex items-center justify-between">
                            <span className="text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                Source
                            </span>
                            {log.source_type ? (
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded-full border border-border bg-background px-2.5 py-1 font-mono text-[10px] tracking-wider text-muted-foreground uppercase transition-colors hover:bg-muted hover:text-foreground"
                                >
                                    {log.source_type}
                                    <ExternalLink className="h-3 w-3" />
                                </button>
                            ) : null}
                        </div>
                        <div className="rounded-md border border-border bg-muted/30 px-3 py-2 font-mono text-xs">
                            {log.source_label ?? (
                                <span className="text-muted-foreground">—</span>
                            )}
                        </div>
                    </div>

                    {log.user_name ? (
                        <div>
                            <div className="mb-2 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase">
                                User
                            </div>
                            <div className="rounded-md border border-border bg-muted/30 px-3 py-2 text-xs">
                                {log.user_name}
                            </div>
                        </div>
                    ) : null}

                    <ContextBlock context={log.context} />
                </div>
            </aside>
        </>
    );
}

function ContextBlock({
    context,
}: {
    context: Record<string, unknown> | null;
}) {
    const [open, setOpen] = useState(true);

    if (!context || Object.keys(context).length === 0) {
        return null;
    }

    const count = Object.keys(context).length;

    return (
        <div>
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                className="mb-2 flex w-full items-center justify-between text-[10px] font-semibold tracking-wider text-muted-foreground uppercase hover:text-foreground"
            >
                <span>Log Context</span>
                <span className="flex items-center gap-1 normal-case">
                    {count} {count === 1 ? 'item' : 'items'}
                    {open ? (
                        <ChevronUp className="h-3 w-3" />
                    ) : (
                        <ChevronDown className="h-3 w-3" />
                    )}
                </span>
            </button>
            {open ? (
                <pre className="overflow-x-auto rounded-md border border-border bg-muted/30 p-3 font-mono text-[11px] leading-relaxed">
                    <JsonView value={context} indent={0} />
                </pre>
            ) : null}
        </div>
    );
}

function JsonView({ value, indent }: { value: unknown; indent: number }) {
    if (value === null) {
        return <span className="text-muted-foreground">null</span>;
    }

    if (typeof value === 'string') {
        return (
            <span className="text-rose-500 dark:text-rose-400">"{value}"</span>
        );
    }

    if (typeof value === 'number') {
        return (
            <span className="text-emerald-600 dark:text-emerald-400">
                {value}
            </span>
        );
    }

    if (typeof value === 'boolean') {
        return (
            <span className="text-amber-600 dark:text-amber-400">
                {String(value)}
            </span>
        );
    }

    if (Array.isArray(value)) {
        if (value.length === 0) {
            return <>[]</>;
        }

        return (
            <>
                {'['}
                {value.map((item, i) => (
                    <div key={i} style={{ paddingLeft: (indent + 1) * 12 }}>
                        <JsonView value={item} indent={indent + 1} />
                        {i < value.length - 1 ? ',' : ''}
                    </div>
                ))}
                <div style={{ paddingLeft: indent * 12 }}>{']'}</div>
            </>
        );
    }

    if (typeof value === 'object') {
        const entries = Object.entries(value as Record<string, unknown>);

        if (entries.length === 0) {
            return <>{'{}'}</>;
        }

        return (
            <>
                {'{'}
                {entries.map(([k, v], i) => (
                    <div key={k} style={{ paddingLeft: (indent + 1) * 12 }}>
                        <span className="text-rose-600 dark:text-rose-400">
                            "{k}"
                        </span>
                        <span className="text-muted-foreground">: </span>
                        <JsonView value={v} indent={indent + 1} />
                        {i < entries.length - 1 ? ',' : ''}
                    </div>
                ))}
                <div style={{ paddingLeft: indent * 12 }}>{'}'}</div>
            </>
        );
    }

    return <>{String(value)}</>;
}

function LevelBadge({ level }: { level: string }) {
    const color = levelColor(level);

    return (
        <span
            className={cn(
                'font-mono text-[10px] tracking-wider uppercase',
                color,
            )}
        >
            [{level}]
        </span>
    );
}

function levelColor(level: string): string {
    switch (level) {
        case 'debug':
            return 'text-slate-500 dark:text-slate-400';
        case 'info':
            return 'text-sky-600 dark:text-sky-400';
        case 'notice':
            return 'text-cyan-600 dark:text-cyan-400';
        case 'warning':
            return 'text-amber-600 dark:text-amber-400';
        case 'error':
            return 'text-rose-600 dark:text-rose-400';
        case 'critical':
            return 'text-red-700 dark:text-red-400';
        default:
            return 'text-muted-foreground';
    }
}

function levelDotColor(level: string): string {
    switch (level) {
        case 'debug':
            return 'bg-slate-400';
        case 'info':
            return 'bg-sky-500';
        case 'notice':
            return 'bg-cyan-500';
        case 'warning':
            return 'bg-amber-500';
        case 'error':
            return 'bg-rose-500';
        case 'critical':
            return 'bg-red-600';
        default:
            return 'bg-muted-foreground';
    }
}

function formatStamp(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const d = new Date(iso);
    const date = d.toISOString().slice(0, 10);
    const time = d.toISOString().slice(11, 19);

    return `${date} ${time} UTC`;
}

function formatStampFull(iso: string | null): string {
    if (!iso) {
        return '';
    }

    const d = new Date(iso);

    return (
        d.toLocaleString([], {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            timeZone: 'UTC',
        }) + ' UTC'
    );
}
