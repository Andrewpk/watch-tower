<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Ingest Limits
    |--------------------------------------------------------------------------
    |
    | Guards for the ingest endpoints (/api/nightwatch-ingest, /api/ingest and
    | /api/agent-auth). They bound how much work a single request, or a single
    | project, can make the application do.
    |
    | max_decoded_bytes:     Largest request body accepted by the Nightwatch
    |                        ingest endpoint, measured after gzip decoding.
    | max_records:           Most records accepted in one Nightwatch payload.
    | rate_limit_per_minute: Requests per minute, per project, across the
    |                        ingest endpoints. 0 disables the limit.
    | agent_auth_per_minute: Requests per minute, per client IP, to the
    |                        agent-auth endpoint. 0 disables the limit.
    |
    */

    'ingest' => [
        'max_decoded_bytes' => (int) env('WATCH_INGEST_MAX_DECODED_BYTES', 10 * 1024 * 1024),
        'max_records' => (int) env('WATCH_INGEST_MAX_RECORDS', 5000),
        'rate_limit_per_minute' => (int) env('WATCH_INGEST_RATE_LIMIT_PER_MINUTE', 600),
        'agent_auth_per_minute' => (int) env('WATCH_INGEST_AGENT_AUTH_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maintenance Warnings
    |--------------------------------------------------------------------------
    |
    | Thresholds for the warnings super admins see when background work is not
    | running (see App\Watch\MaintenanceStatus).
    |
    | prune_overdue_hours:   Hours after which a retention prune that has not
    |                        finished counts as overdue. The prune is daily.
    | queue_stalled_minutes: Minutes a queued job may wait, unclaimed, before
    |                        the queue counts as stalled. Only checked for the
    |                        `database` queue driver.
    |
    */

    'maintenance' => [
        'prune_overdue_hours' => (int) env('WATCH_PRUNE_OVERDUE_HOURS', 25),
        'queue_stalled_minutes' => (int) env('WATCH_QUEUE_STALLED_MINUTES', 10),
    ],

];
