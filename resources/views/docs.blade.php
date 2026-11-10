<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Technical Documentation &amp; Restore Guide — {{ $appName }}</title>
    @if(!empty($faviconUrl))
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/landing/landing.css') }}">
</head>
<body>

    @include('partials.public-header')

    <main class="section" style="padding-top: 56px; padding-bottom: 40px;">
        <div class="container" style="max-width: 980px;">
            <div class="section-head" style="text-align: left; margin-bottom: 36px;" data-reveal>
                <span class="kicker">Developer &amp; Agency Documentation</span>
                <h1 style="font-size: clamp(2rem, 3.6vw, 2.8rem); margin: 10px 0 12px;">
                    {{ $appName }} — Technical Architecture &amp; Restore Guide
                </h1>
                <p style="margin: 0; color: var(--muted);">
                    Complete reference for Domain Ownership Verification, Multi-Protocol Server Connectors (SSH/SFTP, cPanel UAPI, FTP), 100% Read-Only Malware Scanning, Live Uptime/SSL Radar, and 1-Click Disaster Recovery.
                </p>
            </div>

            <div style="display: grid; gap: 24px;">
                <div id="verification" class="panel" style="padding: 28px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border);" data-reveal>
                    <h2 style="margin-top: 0; font-size: 1.35rem;">1. Mandatory Domain Ownership Verification &amp; SSRF Protection</h2>
                    <p style="color: var(--muted);">Before any server connection or scheduled backup can execute, verify domain ownership using one of three cryptographic methods:</p>
                    <ul style="line-height: 1.8;">
                        <li><strong>DNS TXT Record:</strong> Add <code>_sitevaulta-verify.yourdomain.com</code> with your unique token.</li>
                        <li><strong>HTML Verification File:</strong> Upload <code>/.well-known/sitevaulta-verify.txt</code> to your web root.</li>
                        <li><strong>Homepage Meta Tag:</strong> Add <code>&lt;meta name="sitevaulta-verification" content="sv-verify-..."&gt;</code> inside <code>&lt;head&gt;</code>.</li>
                    </ul>
                </div>

                <div id="uptime-ssl" class="panel" style="padding: 28px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border);" data-reveal>
                    <h2 style="margin-top: 0; font-size: 1.35rem;">2. 24/7 Live Domain, Server &amp; SSL Certificate Expiry Guard</h2>
                    <p style="color: var(--muted);">Every verified website is automatically monitored every 5 minutes across DNS, HTTP/HTTPS, SSL port 443, and its connected server (SSH/SFTP port 22, cPanel UAPI port 2083, or FTP port 21):</p>
                    <ul style="line-height: 1.8;">
                        <li><strong>Instant Downtime &amp; Recovery Alerts:</strong> Toggle Downtime and Recovery alerts individually per website.</li>
                        <li><strong>SSL Certificate Expiry Auto-Reminders:</strong> Receive automatic warnings <strong>30, 14, 7, or 3 days</strong> before your HTTPS SSL certificate expires.</li>
                        <li><strong>Smart Email Delivery Auto-Fallback:</strong> Choose between your <strong>Own Custom SMTP (Free &amp; Unlimited)</strong> or <strong>System Email Digital Wallet</strong>. If your primary method is unavailable, SiteVaulta automatically falls back to the other so you never miss a critical alert.</li>
                    </ul>
                </div>

                <div id="malware-scanner" class="panel" style="padding: 28px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border);" data-reveal>
                    <h2 style="margin-top: 0; font-size: 1.35rem;">3. 100% Read-Only Malware, Web-Shell &amp; Exposure Scanner</h2>
                    <p style="color: var(--muted);">
                        <strong>Strict Non-Destructive Guarantee:</strong> SiteVaulta never deletes, modifies, or writes any file on your live server. Our security engine operates in 100% Read-Only diagnostic mode:
                    </p>
                    <ul style="line-height: 1.8;">
                        <li><strong>PHP Web-Shell &amp; Backdoor Signatures:</strong> Detects <code>c99</code>, <code>r57</code>, <code>WSO</code>, <code>b374k</code>, <code>FilesMan</code>, <code>eval(base64_decode(...))</code>, and <code>shell_exec($_POST[...])</code> with exact server file path and line number.</li>
                        <li><strong>Uploads Directory Guard:</strong> Flags any executable <code>.php</code>, <code>.phtml</code>, or <code>.phar</code> file hidden inside media upload folders (<code>wp-content/uploads</code>, <code>storage/app/public</code>).</li>
                        <li><strong>Public Exposure Check:</strong> Alerts you immediately if <code>/.env</code> or <code>/.git/config</code> becomes publicly accessible over HTTP.</li>
                    </ul>
                </div>

                <div id="encryption" class="panel" style="padding: 28px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border);" data-reveal>
                    <h2 style="margin-top: 0; font-size: 1.35rem;">4. Pre-Deployment CI/CD Backup Webhook API</h2>
                    <p style="color: var(--muted);">Trigger an automated pre-deploy backup before pushing code from GitHub Actions or GitLab CI:</p>
                    <div class="terminal" style="margin-top: 12px;">
                        <pre style="min-height: auto; padding: 16px;"><code>curl -X POST "{{ url('/api/v1/websites/{website_uuid}/trigger-backup') }}" \
  -H "X-Webhook-Token: YOUR_WEBSITE_WEBHOOK_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"sync": false}'</code></pre>
                    </div>
                </div>

                <div id="sha256" class="panel" style="padding: 28px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border);" data-reveal>
                    <h2 style="margin-top: 0; font-size: 1.35rem;">5. SHA-256 Verification &amp; 1-Command Disaster Recovery</h2>
                    <p style="color: var(--muted);">Every backup run produces a SHA-256 checksum and a context-aware restore guide inside <strong>Backup Runs &amp; Restore</strong>:</p>
                    <div class="terminal" style="margin-top: 12px;">
                        <pre style="min-height: auto; padding: 16px;"><code># 1. Verify archive integrity
sha256sum -c backup-archive.zip.sha256

# 2. Extract files and restore database
unzip backup-archive.zip -d ./restore-staging
gunzip &lt; ./restore-staging/database/db-dump.sql.gz | mysql -u db_user -p db_name</code></pre>
                    </div>
                </div>
            </div>
        </div>
    </main>

    @include('partials.public-footer')

    <script src="{{ asset('assets/landing/landing.js') }}"></script>
</body>
</html>
