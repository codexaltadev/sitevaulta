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

];
