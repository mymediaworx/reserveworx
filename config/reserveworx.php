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
    | Seconds for each cron-triggered queue worker. Keep this inside one
    | cron slot until hosting limits are verified.
    */
    'queue_work_max_time' => (int) env('QUEUE_WORK_MAX_TIME', 50),

];
