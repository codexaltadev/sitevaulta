<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitoring IPs &amp; Allowlisting — {{ $appName }}</title>
    <meta name="description" content="IP addresses and User-Agent used by {{ $appName }} uptime monitoring, and how to allowlist them in your firewall.">
    @if (! empty($faviconUrl))
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/landing/landing.css') }}">
    <style>
        .mi-panel { padding: 26px 28px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); }
        .mi-panel h2 { margin: 0 0 10px; font-size: 1.25rem; }
        .mi-code { display: block; padding: 12px 14px; border-radius: 10px; background: var(--bg); border: 1px solid var(--border); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 14px; overflow-wrap: anywhere; }
        .mi-steps { margin: 8px 0 0; padding-left: 20px; line-height: 1.8; color: var(--muted); }
        .mi-steps strong, .mi-steps code { color: var(--text); }
    </style>
</head>
<body>

    @include('partials.public-header')

    <main class="section" style="padding-top: 56px; padding-bottom: 40px;">
        <div class="container" style="max-width: 900px;">
            <div class="section-head" style="text-align: left; margin-bottom: 28px;">
                <span class="kicker">Uptime monitoring</span>
                <h1 style="font-size: clamp(1.9rem, 3.4vw, 2.6rem); margin: 10px 0 12px;">Monitoring IPs &amp; firewall allowlisting</h1>
                <p style="margin: 0; color: var(--muted);">
                    {{ $appName }} checks your website from the addresses below, at most once per monitoring interval.
                    If a firewall or bot protection blocks these checks, your site can look "down" even though visitors reach it fine —
                    allowlist the IPs and User-Agent to get accurate results.
                </p>
            </div>

            <div style="display: grid; gap: 20px;">
                <section class="mi-panel">
                    <h2>IP addresses</h2>
                    @if ($probeIps === [])
                        <p style="color: var(--muted); margin: 0;">The monitoring IP list is being prepared. Please contact support for the current addresses.</p>
                    @else
                        <code class="mi-code">{!! implode('<br>', array_map('e', $probeIps)) !!}</code>
                        <p style="color: var(--muted); margin: 10px 0 0; font-size: 14px;">
                            Machine-readable list (one IP per line): <a href="{{ route('monitoring.ips.txt') }}">{{ route('monitoring.ips.txt') }}</a>
                        </p>
                    @endif
                </section>

                <section class="mi-panel">
                    <h2>User-Agent</h2>
                    <code class="mi-code">{{ $userAgent }}</code>
                    <p style="color: var(--muted); margin: 10px 0 0; font-size: 14px;">Every HTTP check sends this header, so you can recognise our requests in your access logs.</p>
                </section>

                <section class="mi-panel">
                    <h2>Cloudflare</h2>
                    <ol class="mi-steps">
                        <li>Open your domain → <strong>Security → WAF → Tools</strong> (IP Access Rules).</li>
                        <li>Add each IP above with action <strong>Allow</strong>, scope <strong>This website</strong>.</li>
                        <li>If <strong>Bot Fight Mode</strong> or <strong>Under Attack Mode</strong> is on, the IP rule lets our checks through.</li>
                    </ol>
                </section>

                <section class="mi-panel">
                    <h2>WordPress — Wordfence</h2>
                    <ol class="mi-steps">
                        <li>Go to <strong>Wordfence → Firewall → All Firewall Options</strong>.</li>
                        <li>Paste the IPs into <strong>Allowlisted IP addresses that bypass all rules</strong> and save.</li>
                    </ol>
                </section>

                <section class="mi-panel">
                    <h2>cPanel / WHM — CSF or Imunify360</h2>
                    <ol class="mi-steps">
                        <li><strong>CSF:</strong> WHM → ConfigServer Security &amp; Firewall → <strong>Firewall Allow IPs</strong>, add each IP (or run <code>csf -a IP</code>).</li>
                        <li><strong>Imunify360:</strong> Firewall → <strong>White list</strong> → Add IP.</li>
                    </ol>
                </section>

                <section class="mi-panel">
                    <h2>Sucuri, ModSecurity and other firewalls</h2>
                    <ol class="mi-steps">
                        <li><strong>Sucuri:</strong> Firewall dashboard → Access Control → <strong>Allowlist IP Addresses</strong>.</li>
                        <li><strong>ModSecurity / nginx / Apache:</strong> add an allow rule for the IPs, or exclude our User-Agent from bot and rate-limit rules.</li>
                        <li>Port checks also need the ports opened for these IPs in your server firewall.</li>
                    </ol>
                </section>
            </div>
        </div>
    </main>

    @include('partials.public-footer')

    <script src="{{ asset('assets/landing/landing.js') }}"></script>
</body>
</html>
