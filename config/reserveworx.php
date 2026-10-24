<?php

$platformDomains = array_values(array_unique(array_filter(array_map(
    static fn (string $host): string => strtolower(trim($host)),
    explode(',', (string) env('PLATFORM_DOMAINS', 'app.reserveworx.com')),
))));

return [

    /*
    | Hosts that serve the platform itself and never imply a tenant.
    | Production should list app.reserveworx.com only. Local development
    | can add localhost. A tenant id from the client is never accepted here.
    */
    'platform_domains' => $platformDomains,

    /*
    | Seconds for each cron-triggered queue worker.
    | GoGeek budget, reported by BrandWorx Dev for distrosku.com on Oct 23, 2026
    | and not yet verified on app.reserveworx.com: 4,000 CPU-seconds/hour and
    | 4,000 PHP executions/hour. Cron every minute is 60 schedule:run executions.
    | Each of those may start one queue:work. 60 x 20 seconds = 1,200 CPU-seconds,
    | leaving the rest of the hour for web requests. Do not raise this without
    | recounting that budget.
    */
    'queue_work_max_time' => (int) env('QUEUE_WORK_MAX_TIME', 20),

    /*
    | Authenticated API limits from spec Section 12. The public booking
    | widget already uses the separate "widget" limiter.
    */
    'availability_rate_per_minute' => (int) env('AVAILABILITY_RATE_PER_MINUTE', 60),

    'checkout_rate_per_minute' => (int) env('CHECKOUT_RATE_PER_MINUTE', 10),

];
