import { Link, router, usePage } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    ExternalLink,
    Search,
} from 'lucide-react';
import { useState } from 'react';

import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { AppLayout } from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import issues from '@/routes/projects/issues';
import type { SharedProps } from '@/types/inertia';
import type { Paginated } from '@/types/pagination';

type IssueRow = {
    id: string;
    display_number: number;
    exception_class: string;
    short_class: string;
    first_message: string;
    first_file: string | null;
    first_line: number | null;
    total_count: number;
    users_count: number;
    first_occurrence_at: string | null;
    last_occurrence_at: string | null;
    status: string;
    priority: string;
    is_handled: boolean;
    assigned_to: { id: number; name: string; email: string } | null;
    sparkline: number[];
};

type Counts = {
    open: number;
    unassigned: number;
    mine: number;
    resolved: number;
    ignored: number;
    all: number;
};

type Filters = {
    status: string;
    search: string;
    sort: string;
    direction: 'asc' | 'desc';
};

type Props = {
    groups: Paginated<IssueRow>;
    filters: Filters;
    counts: Counts;
};

const STATUS_TABS = [
    { value: 'open', label: 'Open', countKey: 'open' as const },
    {
        value: 'unassigned',
        label: 'Unassigned',
        countKey: 'unassigned' as const,
    },
    { value: 'mine', label: 'Mine', countKey: null },
    { value: 'resolved', label: 'Resolved', countKey: null },
    { value: 'ignored', label: 'Ignored', countKey: null },
];

const GRID_COLS =
    'grid-cols-[60px_36px_minmax(0,1fr)_72px_72px_88px_88px_120px_90px_90px_36px]';

export default function IssuesIndex({ groups, filters, counts }: Props) {
    const { props } = usePage<SharedProps>();
    const projectSlug = props.currentProject?.slug ?? '';
    const [search, setSearch] = useState(filters.search);

    const visit = (params: Record<string, string | null>) => {
        const url = new URL(window.location.href);
        Object.entries(params).forEach(([key, value]) => {
            if (value === null || value === '') {
                url.searchParams.delete(key);
            } else {
                url.searchParams.set(key, value);
            }
        });

        if ('status' in params || 'search' in params) {
            url.searchParams.delete('page');
        }

        router.visit(url.pathname + url.search, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const submitSearch = (event: React.FormEvent) => {
        event.preventDefault();
        visit({ search: search.trim() || null });
    };

    const onSort = (key: string) => {
        if (filters.sort === key) {
            visit({ direction: filters.direction === 'asc' ? 'desc' : 'asc' });
        } else {
            visit({ sort: key, direction: 'desc' });
        }
    };

    return (
        <AppLayout title="Issues">
            <PageHeader title="Issues" />

            <div className="flex flex-col gap-4 px-6 py-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <PillTabs
                        active="exceptions"
                        tabs={[
                            {
                                value: 'exceptions',
                                label: 'Exceptions',
                                count: counts.all,
                            },
                        ]}
                    />

                    <div className="flex items-center gap-3">
                        <form onSubmit={submitSearch} className="relative">
                            <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                type="search"
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search"
                                className="h-8 w-56 pl-8 text-xs"
                            />
                        </form>

                        <PillTabs
                            active={filters.status}
                            onChange={(value) => visit({ status: value })}
                            tabs={STATUS_TABS.map((tab) => ({
                                value: tab.value,
                                label: tab.label,
                                count: tab.countKey
                                    ? counts[tab.countKey]
                                    : undefined,
                            }))}
                        />
                    </div>
                </div>

                <Card className="overflow-hidden p-0">
                    <div
                        className={cn(
                            'grid items-center gap-4 border-b border-border bg-card px-4 py-2.5 text-[10px] font-semibold tracking-wider text-muted-foreground uppercase',
                            GRID_COLS,
                        )}
                    >
                        <SortHeader
                            label="ID"
                            sortKey="id"
                            current={filters.sort}
                            direction={filters.direction}
                            onSort={onSort}
                        />
                        <span />
                        <span>Issue</span>
                        <SortHeader
                            label="Count"
                            sortKey="count"
                            current={filters.sort}
                            direction={filters.direction}
                            onSort={onSort}
                            align="right"
                        />
                        <SortHeader
                            label="Users"
                            sortKey="users"
                            current={filters.sort}
                            direction={filters.direction}
                            onSort={onSort}
                            align="right"
                        />
                        <SortHeader
                            label="First seen"
                            sortKey="first_seen"
                            current={filters.sort}
                            direction={filters.direction}
                            onSort={onSort}
                            align="right"
                        />
                        <SortHeader
                            label="Last seen"
                            sortKey="last_seen"
                            current={filters.sort}
                            direction={filters.direction}
                            onSort={onSort}
                            align="right"
                        />
                        <span>Assigned</span>
                        <span>Status</span>
                        <span>Priority</span>
                        <span />
                    </div>

                    {groups.data.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <ul className="divide-y divide-border">
                            {groups.data.map((issue) => (
                                <IssueRowItem
                                    key={issue.id}
                                    issue={issue}
                                    projectSlug={projectSlug}
                                />
                            ))}
                        </ul>
                    )}

                    <Pagination
                        links={groups.links}
                        from={groups.from}
                        to={groups.to}
                        total={groups.total}
                    />
                </Card>
            </div>
        </AppLayout>
    );
}

function PillTabs({
    active,
    tabs,
    onChange,
}: {
    active: string;
    tabs: {
        value: string;
        label: string;
        count?: number;
        disabled?: boolean;
    }[];
    onChange?: (value: string) => void;
}) {
    return (
        <div className="inline-flex items-center gap-1 rounded-md border border-border bg-card p-1">
            {tabs.map((tab) => {
                const isActive = tab.value === active;
                const className = cn(
                    'inline-flex items-center gap-1.5 rounded px-2.5 py-1 text-xs font-medium transition-colors',
                    isActive
                        ? 'bg-muted text-foreground'
                        : 'text-muted-foreground hover:text-foreground',
                    tab.disabled && 'cursor-not-allowed opacity-50',
                );

                return (
                    <button
                        key={tab.value}
                        type="button"
                        disabled={tab.disabled || !onChange}
                        onClick={() => !tab.disabled && onChange?.(tab.value)}
                        className={className}
                    >
                        <span>{tab.label}</span>
                        {tab.count !== undefined && (
                            <span
                                className={cn(
                                    'rounded px-1 font-mono text-[10px] tabular-nums',
                                    isActive
                                        ? 'bg-background text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {formatCount(tab.count)}
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

function SortHeader({
    label,
    sortKey,
    current,
    direction,
    onSort,
    align = 'left',
}: {
    label: string;
    sortKey: string;
    current: string;
    direction: 'asc' | 'desc';
    onSort: (key: string) => void;
    align?: 'left' | 'right';
}) {
    const isActive = current === sortKey;
    const Icon = isActive
        ? direction === 'asc'
            ? ArrowUp
            : ArrowDown
        : ArrowUpDown;

    return (
        <button
            type="button"
            onClick={() => onSort(sortKey)}
            className={cn(
                'inline-flex items-center gap-1 transition-colors hover:text-foreground',
                align === 'right' && 'justify-end',
                isActive && 'text-foreground',
            )}
        >
            <span>{label}</span>
            <Icon className="h-3 w-3 opacity-70" />
        </button>
    );
}

function IssueRowItem({
    issue,
    projectSlug,
}: {
    issue: IssueRow;
    projectSlug: string;
}) {
    const href = issues.show([projectSlug, issue.display_number]).url;

    return (
        <li
            className={cn(
                'grid items-center gap-4 px-4 py-3 transition-colors hover:bg-muted/30',
                GRID_COLS,
            )}
        >
            <Link
                href={href}
                className="font-mono text-xs text-muted-foreground hover:text-foreground"
            >
                {issue.display_number}
            </Link>

            <Sparkline values={issue.sparkline} />

            <Link href={href} className="min-w-0">
                <div
                    className="truncate text-sm font-medium text-foreground"
                    title={issue.short_class}
                >
                    {issue.short_class}
                </div>
                <div
                    className="truncate font-mono text-[11px] text-muted-foreground"
                    title={issue.first_message}
                >
                    {issue.first_message || '—'}
                </div>
            </Link>

            <span className="text-right font-mono text-sm tabular-nums">
                {formatCount(issue.total_count)}
            </span>
            <span className="text-right font-mono text-sm tabular-nums">
                {formatCount(issue.users_count)}
            </span>
            <span className="text-right font-mono text-[11px] text-muted-foreground">
                {formatRelative(issue.first_occurrence_at)}
            </span>
            <span className="text-right font-mono text-[11px] text-muted-foreground">
                {formatRelative(issue.last_occurrence_at)}
            </span>

            <span className="flex items-center gap-2 truncate">
                {issue.assigned_to ? (
                    <>
                        <Avatar name={issue.assigned_to.name} />
                        <span className="truncate text-xs">
                            {issue.assigned_to.name}
                        </span>
                    </>
                ) : (
                    <Avatar name="?" muted />
                )}
            </span>

            <StatusBadge status={issue.status} />
            <PriorityBadge priority={issue.priority} />

            <Link
                href={href}
                aria-label="Open issue"
                className="flex h-7 w-7 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
            >
                <ExternalLink className="h-3.5 w-3.5" />
            </Link>
        </li>
    );
}

function Sparkline({ values }: { values: number[] }) {
    const data = values && values.length > 0 ? values : [0];
    const max = Math.max(1, ...data);
    const width = 28;
    const height = 16;
    const barWidth = width / data.length;

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className="text-muted-foreground/60"
        >
            {data.map((value, i) => {
                const h = Math.max(1, (value / max) * height);

                return (
                    <rect
                        key={i}
                        x={i * barWidth}
                        y={height - h}
                        width={Math.max(1, barWidth - 1)}
                        height={h}
                        rx={0.5}
                        fill="currentColor"
                    />
                );
            })}
        </svg>
    );
}

function StatusBadge({ status }: { status: string }) {
    const config: Record<string, { label: string; dot: string; text: string }> =
        {
            unresolved: {
                label: 'Open',
                dot: 'bg-blue-500',
                text: 'text-foreground',
            },
            resolved: {
                label: 'Resolved',
                dot: 'bg-emerald-500',
                text: 'text-foreground',
            },
            ignored: {
                label: 'Ignored',
                dot: 'bg-muted-foreground',
                text: 'text-muted-foreground',
            },
        };
    const c = config[status] ?? config.unresolved;

    return (
        <span
            className={cn('inline-flex items-center gap-1.5 text-xs', c.text)}
        >
            <span className={cn('h-1.5 w-1.5 rounded-full', c.dot)} />
            {c.label}
        </span>
    );
}

function PriorityBadge({ priority }: { priority: string }) {
    const config: Record<string, { label: string; className: string }> = {
        none: { label: 'None', className: 'text-muted-foreground' },
        low: { label: 'Low', className: 'text-muted-foreground' },
        medium: { label: 'Medium', className: 'text-amber-500' },
        high: { label: 'High', className: 'text-rose-500' },
    };
    const c = config[priority] ?? config.none;

    return <span className={cn('text-xs', c.className)}>{c.label}</span>;
}

function Avatar({ name, muted = false }: { name: string; muted?: boolean }) {
    return (
        <span
            className={cn(
                'grid h-6 w-6 place-items-center rounded-full text-[10px] font-semibold',
                muted
                    ? 'border border-dashed border-muted-foreground/40 text-muted-foreground/60'
                    : 'bg-emerald-500/20 text-emerald-600 dark:text-emerald-300',
            )}
            title={name}
        >
            {muted ? '' : name.charAt(0).toUpperCase()}
        </span>
    );
}

function EmptyState() {
    return (
        <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
            <p className="text-sm font-medium">No issues match the filter</p>
            <p className="mt-1 text-xs text-muted-foreground">
                Try widening the status filter.
            </p>
        </div>
    );
}

function formatCount(n: number): string {
    if (n >= 1000) {
        return (n / 1000).toFixed(n >= 10000 ? 0 : 1) + 'k';
    }

    return n.toLocaleString();
}

function formatRelative(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    const date = new Date(iso);
    const diff = Date.now() - date.getTime();
    const seconds = Math.floor(diff / 1000);

    if (seconds < 60) {
        return `${seconds}s ago`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m ago`;
    }

    if (seconds < 86400) {
        return `${Math.floor(seconds / 3600)}h ago`;
    }

    if (seconds < 86400 * 30) {
        return `${Math.floor(seconds / 86400)}d ago`;
    }

    return date.toLocaleDateString();
}
