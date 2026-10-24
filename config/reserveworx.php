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
    | Public signup addresses are {slug}.{apex}. Production uses reserveworx.com,
    | so a request for "lakeside" becomes lakeside.reserveworx.com.
    */
    'booking_apex' => strtolower(trim((string) env('BOOKING_APEX', 'reserveworx.com'))),

    /*
    | Labels a new business cannot claim. The first label of each platform host
    | is reserved as well, so app.reserveworx.com stays the platform.
    */
    'reserved_subdomains' => [
        'app', 'www', 'admin', 'api', 'mail', 'staff', 'platform',
        'ftp', 'smtp', 'pop', 'imap', 'webmail', 'ns', 'ns1', 'ns2', 'dns', 'mx',
        'localhost', 'test', 'staging', 'stage', 'dev', 'demo', 'status', 'support', 'help',
        'billing', 'docs', 'blog', 'cdn', 'static', 'assets', 'cpanel', 'whm', 'webdisk',
        'autoconfig', 'autodiscover', 'reserveworx', 'brandworx', 'stripe', 'webhook', 'webhooks',
        'login', 'signup', 'register', 'dashboard', 'root', 'system', 'security', 'ssl',
        'email', 'noreply', 'no-reply', 'postmaster', 'hostmaster', 'abuse', 'webmaster',
        'phpmyadmin', 'mysql', 'database', 'git', 'ssh', 'sftp', 'vpn', 'proxy', 'internal',
        'auth', 'oauth', 'sso', 'account', 'accounts', 'user', 'users',
    ],

    'signup_rate_per_minute' => (int) env('SIGNUP_RATE_PER_MINUTE', 5),

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
