<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Probe IP addresses
    |--------------------------------------------------------------------------
    |
    | Public IPs our uptime checks leave from, published on /monitoring-ips so
    | customers can allowlist them in Cloudflare, Wordfence, CSF, etc.
    | Comma-separated. When empty, the server's public IP is detected and cached.
    |
    */

    'probe_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_PROBE_IPS', ''))))),

    'detect_public_ip' => (bool) env('MONITORING_DETECT_PUBLIC_IP', true),

    /*
    |--------------------------------------------------------------------------
    | User-Agent
    |--------------------------------------------------------------------------
    |
    | Sent with every HTTP check. The URL of the public IP page is appended so
    | site owners who see it in their logs know what it is and how to allow it.
    |
    */

    'user_agent_name' => env('MONITORING_USER_AGENT_NAME', 'SiteVaulta-Monitor/1.0'),

    /*
    |--------------------------------------------------------------------------
    | Failure confirmation
    |--------------------------------------------------------------------------
    |
    | A failed check is re-checked after a short pause before it counts. When
    | the re-check also fails with a DNS / connection / timeout error, the probe
    | first checks its own network against well-known hosts: if those fail too,
    | the fault is ours, so nothing is recorded and no one is alerted.
    |
    */

    'confirmation_delay_seconds' => (int) env('MONITORING_CONFIRMATION_DELAY_SECONDS', 3),

    'control_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('MONITORING_CONTROL_HOSTS', 'google.com,cloudflare.com,amazon.com'))))),

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    |
    | Checks run as queued jobs on their own queue, so a long backup job never
    | delays them. Run a worker with: php artisan queue:work --queue=monitoring
    |
    */

    'queue' => env('MONITORING_QUEUE', 'monitoring'),

    /*
    |--------------------------------------------------------------------------
    | Data retention (days)
    |--------------------------------------------------------------------------
    |
    | Raw checks feed the 1h / 24h response chart and the incident details;
    | hourly totals feed the 24h bars, 7/30-day uptime and the 7-day chart;
    | daily totals feed the 365-day uptime and status page bars.
    |
    */

    'retention' => [
        'raw_days' => (int) env('MONITORING_RAW_RETENTION_DAYS', 30),
        'hourly_days' => (int) env('MONITORING_HOURLY_RETENTION_DAYS', 90),
        'daily_days' => (int) env('MONITORING_DAILY_RETENTION_DAYS', 730),
    ],

    /*
    |--------------------------------------------------------------------------
    | Probe agent mode
    |--------------------------------------------------------------------------
    |
    | Set MONITORING_AGENT_MODE=true on a server in another region to make this
    | application a probe agent: it only answers signed /probe-agent requests
    | from the main server (Admin → Probe locations) with the same secret.
    |
    */

    'agent' => [
        'mode' => (bool) env('MONITORING_AGENT_MODE', false),
        'secret' => env('MONITORING_AGENT_SECRET'),
        'max_clock_skew_seconds' => 60,
    ],

];
