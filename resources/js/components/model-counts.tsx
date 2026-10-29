type ModelCountsProps = {
    lazyLoads: number | null;
    hydratedModels: number | null;
};

/**
 * Lazy load and hydrated model counts of one run as `lazy / models`. A count the sensor did not report is shown as
 * a dash, and a run with lazy loads is highlighted since each one is a potential N+1.
 */
export function ModelCounts({ lazyLoads, hydratedModels }: ModelCountsProps) {
    const format = (value: number | null) =>
        value === null ? '—' : new Intl.NumberFormat().format(value);

    return (
        <span
            title={`${format(lazyLoads)} lazy loads, ${format(hydratedModels)} models hydrated`}
            className="whitespace-nowrap"
        >
            <span
                className={
                    lazyLoads !== null && lazyLoads > 0
                        ? 'font-semibold text-amber-600 dark:text-amber-400'
                        : undefined
                }
            >
                {format(lazyLoads)}
            </span>
            <span className="text-muted-foreground"> / </span>
            <span>{format(hydratedModels)}</span>
        </span>
    );
}
