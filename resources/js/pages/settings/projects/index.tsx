import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Check,
    Copy,
    KeyRound,
    Pencil,
    Plus,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';

import ProjectAdminController from '@/actions/App/Http/Controllers/Settings/ProjectAdminController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { SettingsShell } from '@/layouts/settings-shell';
import type { NewApiKey, SharedProps } from '@/types/inertia';

type Project = {
    id: string;
    name: string;
    slug: string;
    description: string | null;
    api_key_prefix: string | null;
    api_key_last_used_at: string | null;
    previous_api_key_expires_at: string | null;
    sampling_rate: number;
    retention_days: number;
    admins_count: number;
    open_issues_count: number;
    created_at: string | null;
};

type Props = {
    projects: Project[];
};

const GRACE_OPTIONS = [
    {
        hours: 0,
        label: 'None',
        hint: 'The old key stops working immediately.',
    },
    { hours: 1, label: '1 hour', hint: 'The old key works for another hour.' },
    {
        hours: 24,
        label: '24 hours',
        hint: 'The old key works for another day.',
    },
    { hours: 168, label: '7 days', hint: 'The old key works for a week.' },
];

function formatDateTime(iso: string) {
    return new Date(iso).toLocaleString();
}

export default function ProjectsIndex({ projects }: Props) {
    const { props, flash } = usePage<
        SharedProps & { flash?: { success?: string } }
    >();
    const flashSuccess = props.flash?.success;
    const flashedApiKey = flash.new_api_key ?? null;
    const [dismissedKey, setDismissedKey] = useState<string | null>(null);
    const newApiKey =
        flashedApiKey && flashedApiKey.key !== dismissedKey
            ? flashedApiKey
            : null;
    const [target, setTarget] = useState<Project | null>(null);
    const [rotateTarget, setRotateTarget] = useState<Project | null>(null);

    return (
        <SettingsShell>
            <Head title="Projects" />
            <div className="flex items-center justify-between border-b border-border px-8 py-6">
                <div>
                    <h1 className="text-2xl font-semibold tracking-tight">
                        Projects
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Create projects and assign admin access.
                    </p>
                </div>
                <Button asChild size="sm">
                    <Link href={ProjectAdminController.create.url()}>
                        <Plus className="mr-1 h-3.5 w-3.5" />
                        New project
                    </Link>
                </Button>
            </div>

            <div className="mx-auto w-full max-w-5xl px-8 py-8">
                {flashSuccess && (
                    <div className="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700 dark:border-emerald-900/50 dark:bg-emerald-950/40 dark:text-emerald-300">
                        {flashSuccess}
                    </div>
                )}

                {newApiKey && (
                    <NewKeyPanel
                        apiKey={newApiKey}
                        onDismiss={() => setDismissedKey(newApiKey.key)}
                    />
                )}

                <div className="overflow-hidden rounded-lg border border-border bg-card">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-xs tracking-wide text-muted-foreground uppercase">
                            <tr>
                                <th className="px-4 py-2.5 text-left font-medium">
                                    Project
                                </th>
                                <th className="px-4 py-2.5 text-left font-medium">
                                    API key
                                </th>
                                <th className="px-4 py-2.5 text-left font-medium">
                                    Sampling
                                </th>
                                <th className="px-4 py-2.5 text-left font-medium">
                                    Retention
                                </th>
                                <th className="px-4 py-2.5 text-left font-medium">
                                    Admins
                                </th>
                                <th className="px-4 py-2.5 text-left font-medium">
                                    Open issues
                                </th>
                                <th className="px-4 py-2.5"></th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-border">
                            {projects.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={7}
                                        className="px-4 py-10 text-center text-sm text-muted-foreground"
                                    >
                                        No projects yet.
                                    </td>
                                </tr>
                            ) : (
                                projects.map((project) => (
                                    <tr key={project.id}>
                                        <td className="px-4 py-3">
                                            <div className="font-medium">
                                                {project.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {project.slug}
                                            </div>
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className="inline-flex rounded-md border border-border bg-background px-2 py-1 font-mono text-xs text-muted-foreground">
                                                {project.api_key_prefix
                                                    ? `${project.api_key_prefix}…`
                                                    : '—'}
                                            </span>
                                            <div className="mt-1 text-xs text-muted-foreground">
                                                {project.api_key_last_used_at
                                                    ? `Last used ${formatDateTime(project.api_key_last_used_at)}`
                                                    : 'Never used'}
                                            </div>
                                            {project.previous_api_key_expires_at && (
                                                <div className="mt-0.5 text-xs text-amber-600 dark:text-amber-400">
                                                    Previous key valid until{' '}
                                                    {formatDateTime(
                                                        project.previous_api_key_expires_at,
                                                    )}
                                                </div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {Math.round(
                                                project.sampling_rate * 100,
                                            )}
                                            %
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {project.retention_days}d
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            <span className="inline-flex items-center gap-1">
                                                <Users className="h-3.5 w-3.5" />
                                                {project.admins_count}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground">
                                            {project.open_issues_count}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <div className="flex items-center justify-end gap-1">
                                                <Button
                                                    asChild
                                                    variant="ghost"
                                                    size="sm"
                                                >
                                                    <Link
                                                        href={ProjectAdminController.edit.url(
                                                            {
                                                                project:
                                                                    project.slug,
                                                            },
                                                        )}
                                                    >
                                                        <Pencil className="h-3.5 w-3.5" />
                                                    </Link>
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    title="Rotate API key"
                                                    onClick={() =>
                                                        setRotateTarget(project)
                                                    }
                                                >
                                                    <KeyRound className="h-3.5 w-3.5" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    onClick={() =>
                                                        setTarget(project)
                                                    }
                                                    className="text-rose-600 hover:bg-rose-50 hover:text-rose-700 dark:hover:bg-rose-950/40"
                                                >
                                                    <Trash2 className="h-3.5 w-3.5" />
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {rotateTarget && (
                <RotateKeyDialog
                    project={rotateTarget}
                    onClose={() => setRotateTarget(null)}
                />
            )}

            {target && (
                <DeleteDialog
                    project={target}
                    onClose={() => setTarget(null)}
                />
            )}
        </SettingsShell>
    );
}

function NewKeyPanel({
    apiKey,
    onDismiss,
}: {
    apiKey: NewApiKey;
    onDismiss: () => void;
}) {
    return (
        <div
            role="status"
            className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm dark:border-amber-900/50 dark:bg-amber-950/40"
        >
            <div className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold text-amber-900 dark:text-amber-200">
                        API key for {apiKey.project}
                    </h2>
                    <p className="text-xs text-amber-800 dark:text-amber-300">
                        Copy this key now. It won't be shown again, only a short
                        prefix is kept.
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={onDismiss}
                    title="Dismiss"
                    aria-label="Dismiss"
                >
                    <X className="h-3.5 w-3.5" />
                </Button>
            </div>
            <div className="mt-3 flex items-center gap-2">
                <code className="min-w-0 flex-1 rounded-md border border-border bg-background px-3 py-2 font-mono text-xs break-all text-foreground">
                    {apiKey.key}
                </code>
                <CopyButton value={apiKey.key} />
            </div>
        </div>
    );
}

function CopyButton({ value }: { value: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(value);
        } catch {
            const ta = document.createElement('textarea');
            ta.value = value;
            ta.style.position = 'fixed';
            ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
        }

        setCopied(true);
        setTimeout(() => setCopied(false), 1500);
    };

    return (
        <Button variant="outline" size="sm" onClick={copy}>
            {copied ? (
                <Check className="h-3.5 w-3.5 text-emerald-600" />
            ) : (
                <Copy className="h-3.5 w-3.5" />
            )}
            {copied ? 'Copied' : 'Copy'}
        </Button>
    );
}

function RotateKeyDialog({
    project,
    onClose,
}: {
    project: Project;
    onClose: () => void;
}) {
    const [graceHours, setGraceHours] = useState(24);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);

    const submit = () => {
        setProcessing(true);
        setError(null);
        router.post(
            ProjectAdminController.rotateKey.url({ project: project.slug }),
            { grace_hours: graceHours },
            {
                onError: (errs) =>
                    setError(
                        (errs.grace_hours as string) ??
                            'Failed to rotate the API key.',
                    ),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4">
            <div className="w-full max-w-md rounded-lg border border-border bg-card shadow-xl">
                <div className="flex items-start gap-3 border-b border-border px-5 py-4">
                    <span className="grid h-9 w-9 place-items-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400">
                        <KeyRound className="h-5 w-5" />
                    </span>
                    <div>
                        <h2 className="text-base font-semibold tracking-tight">
                            Rotate API key
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            This issues a new key for{' '}
                            <span className="font-medium text-foreground">
                                {project.name}
                            </span>
                            . After the grace period, agents still using the old
                            key are rejected until their NIGHTWATCH_TOKEN is
                            updated.
                        </p>
                    </div>
                </div>

                <div className="px-5 py-4">
                    <fieldset className="space-y-2">
                        <legend className="mb-2 text-xs text-muted-foreground">
                            Keep the old key working for:
                        </legend>
                        {GRACE_OPTIONS.map((option) => (
                            <label
                                key={option.hours}
                                className="flex cursor-pointer items-start gap-3 rounded-md border border-border px-3 py-2 text-sm has-checked:border-primary has-checked:bg-muted/50"
                            >
                                <input
                                    type="radio"
                                    name="grace_hours"
                                    value={option.hours}
                                    checked={graceHours === option.hours}
                                    onChange={() => setGraceHours(option.hours)}
                                    className="mt-1"
                                />
                                <span>
                                    <span className="font-medium">
                                        {option.label}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        {option.hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                        {error && (
                            <p className="text-xs text-rose-600">{error}</p>
                        )}
                    </fieldset>
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-border px-5 py-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button size="sm" onClick={submit} disabled={processing}>
                        {processing ? 'Rotating…' : 'Rotate key'}
                    </Button>
                </div>
            </div>
        </div>
    );
}

function DeleteDialog({
    project,
    onClose,
}: {
    project: Project;
    onClose: () => void;
}) {
    const [confirmation, setConfirmation] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const canDelete = confirmation === project.slug;

    const submit = () => {
        if (!canDelete) {
            return;
        }

        setProcessing(true);
        setError(null);
        router.delete(
            ProjectAdminController.destroy.url({ project: project.slug }),
            {
                data: { confirmation },
                onError: (errs) =>
                    setError(
                        (errs.confirmation as string) ??
                            'Failed to delete project.',
                    ),
                onFinish: () => setProcessing(false),
                onSuccess: () => onClose(),
            },
        );
    };

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 px-4">
            <div className="w-full max-w-md rounded-lg border border-border bg-card shadow-xl">
                <div className="flex items-start gap-3 border-b border-border px-5 py-4">
                    <span className="grid h-9 w-9 place-items-center rounded-full bg-rose-100 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400">
                        <AlertTriangle className="h-5 w-5" />
                    </span>
                    <div>
                        <h2 className="text-base font-semibold tracking-tight">
                            Delete project
                        </h2>
                        <p className="text-xs text-muted-foreground">
                            This permanently deletes{' '}
                            <span className="font-medium text-foreground">
                                {project.name}
                            </span>{' '}
                            and all errors, events, logs, requests, jobs, and
                            other data.
                        </p>
                    </div>
                </div>

                <div className="space-y-2 px-5 py-4">
                    <label className="text-xs text-muted-foreground">
                        Type{' '}
                        <span className="font-mono text-foreground">
                            {project.slug}
                        </span>{' '}
                        to confirm:
                    </label>
                    <Input
                        autoFocus
                        value={confirmation}
                        onChange={(e) => setConfirmation(e.target.value)}
                        placeholder={project.slug}
                        className="font-mono"
                    />
                    {error && <p className="text-xs text-rose-600">{error}</p>}
                </div>

                <div className="flex items-center justify-end gap-2 border-t border-border px-5 py-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={onClose}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        size="sm"
                        onClick={submit}
                        disabled={!canDelete || processing}
                    >
                        {processing ? 'Deleting…' : 'Delete project'}
                    </Button>
                </div>
            </div>
        </div>
    );
}
