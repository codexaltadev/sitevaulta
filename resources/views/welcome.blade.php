<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $appName }} — {{ $tagline }}</title>
    <meta name="description" content="Automated, framework-agnostic website & database backup automation. Stream AES-256 encrypted, SHA-256 verified backups to your own Google Drive, AWS S3, Cloudflare R2 or VPS. Zero vendor lock-in.">
    @if(!empty($faviconUrl))
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    {{-- Dark & Light Mode Bootstrap (Shared key: filament:theme:mode) --}}
    @include('partials.theme-head')

    {{-- Instrument Sans Font --}}
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

    {{-- Landing Page Custom Styles --}}
    <link rel="stylesheet" href="{{ asset('assets/landing/landing.css') }}">
</head>
<body>

    {{-- =====================================================================
         Site Header & Sticky Navigation
         ===================================================================== --}}
    @include('partials.public-header')

    {{-- =====================================================================
         Hero Section: Value Proposition & Animated Live Pipeline Visual
         ===================================================================== --}}
    <section class="hero">
        <div class="hero-bg" aria-hidden="true">
            <div class="grid"></div>
            <div class="blob blob-1"></div>
            <div class="blob blob-2"></div>
        </div>

        <div class="container hero-grid">
            <div class="hero-content" data-reveal="left">
                <div class="eyebrow">
                    <span class="pulse-dot" aria-hidden="true"></span>
                    <span>{{ $trialDays }}-Day Free Trial • Bring Your Own Storage (Zero Lock-In)</span>
                </div>

                <h1>
                    <span>{{ $heroHeading }}</span>
                </h1>

                <p class="lead">
                    {{ $heroSubheading }}
                </p>

                <div class="hero-ctas">
                    <a href="/app/register" class="btn btn-primary btn-lg">
                        <span>{{ $ctaPrimaryText ?? ('Get Started — Free for ' . $trialDays . ' Days') }}</span>
                        <span class="arrow" aria-hidden="true">&rarr;</span>
                    </a>
                    <a href="#forecast" class="btn btn-ghost btn-lg">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <rect x="2" y="3" width="20" height="18" rx="3"></rect>
                            <path d="M6 8h12M6 12h4M6 16h8"></path>
                        </svg>
                        <span>Calculate Storage Savings</span>
                    </a>
                </div>

                <div class="hero-trust">
                    <span>
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                        <span>AES-256-GCM Encrypted</span>
                    </span>
                    <span>
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                        <span>SHA-256 Verified Integrity</span>
                    </span>
                    <span>
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" /></svg>
                        <span>Zero Data Lock-in</span>
                    </span>
                </div>
            </div>

            {{-- Interactive Animated Live Backup Pipeline Visual --}}
            <div class="hero-visual" data-reveal="right">
                <div class="pipeline">
                    <div class="pipeline-top">
                        <div class="window-dots" aria-hidden="true">
                            <i></i><i></i><i></i>
                        </div>
                        <span class="live-chip">
                            <span class="pulse-dot" style="width:7px;height:7px;" aria-hidden="true"></span>
                            Live Backup Stream
                        </span>
                    </div>

                    {{-- Floating Metric Badges --}}
                    <div class="float-card fc-1" aria-hidden="true">
                        <div class="fc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </div>
                        <div>
                            <span>AES-256-GCM</span>
                            <small class="mono">Chunk Encryption</small>
                        </div>
                    </div>

                    <div class="float-card fc-2" aria-hidden="true">
                        <div class="fc-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                        </div>
                        <div>
                            <span>SHA-256 Checksum</span>
                            <small class="mono">Integrity Verified</small>
                        </div>
                    </div>

                    {{-- Animated Vector Pipeline --}}
                    <svg viewBox="0 0 540 280" fill="none" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Animated illustration showing website origin server streaming encrypted backups through SiteVaulta engine to customer cloud storage">
                        <defs>
                            <linearGradient id="hubGradient" x1="210" y1="80" x2="330" y2="200" gradientUnits="userSpaceOnUse">
                                <stop stop-color="#059669" stop-opacity="0.25" />
                                <stop offset="1" stop-color="#0891b2" stop-opacity="0.35" />
                            </linearGradient>
                            <filter id="glowFilter" x="-20%" y="-20%" width="140%" height="140%">
                                <feGaussianBlur stdDeviation="4" result="blur" />
                                <feComposite in="SourceGraphic" in2="blur" operator="over" />
                            </filter>
                        </defs>

                        {{-- Connecting flow lines from Origin to Engine --}}
                        <path class="pv-line" d="M120 75 C 190 75, 190 140, 240 140" />
                        <path class="pv-line" d="M120 140 L 240 140" />
                        <path class="pv-line" d="M120 205 C 190 205, 190 140, 240 140" />

                        {{-- Flow lines from Engine to Cloud Destinations --}}
                        <path class="pv-line" d="M300 140 C 350 140, 350 50, 420 50" />
                        <path class="pv-line" d="M300 140 C 350 140, 350 110, 420 110" />
                        <path class="pv-line" d="M300 140 C 350 140, 350 170, 420 170" />
                        <path class="pv-line" d="M300 140 C 350 140, 350 230, 420 230" />

                        {{-- Animated Dash Packet Streams --}}
                        <path class="pv-flow" d="M120 75 C 190 75, 190 140, 240 140" />
                        <path class="pv-flow" d="M120 140 L 240 140" />
                        <path class="pv-flow" d="M120 205 C 190 205, 190 140, 240 140" />

                        <path class="pv-flow" d="M300 140 C 350 140, 350 50, 420 50" />
                        <path class="pv-flow" d="M300 140 C 350 140, 350 110, 420 110" />
                        <path class="pv-flow" d="M300 140 C 350 140, 350 170, 420 170" />
                        <path class="pv-flow" d="M300 140 C 350 140, 350 230, 420 230" />

                        {{-- Origin Server Nodes (Left) --}}
                        <g transform="translate(14, 52)">
                            <rect class="pv-node" width="108" height="46" rx="10" />
                            <circle cx="20" cy="23" r="5" class="pv-led" />
                            <text x="34" y="22" class="pv-text">MySQL / PG</text>
                            <text x="34" y="36" class="pv-sub">Atomic Dump</text>
                        </g>

                        <g transform="translate(14, 117)">
                            <rect class="pv-node" width="108" height="46" rx="10" />
                            <circle cx="20" cy="23" r="5" class="pv-led l2" />
                            <text x="34" y="22" class="pv-text">Web Files</text>
                            <text x="34" y="36" class="pv-sub">SFTP / SSH Tunnel</text>
                        </g>

                        <g transform="translate(14, 182)">
                            <rect class="pv-node" width="108" height="46" rx="10" />
                            <circle cx="20" cy="23" r="5" class="pv-led l3" />
                            <text x="34" y="22" class="pv-text">cPanel / API</text>
                            <text x="34" y="36" class="pv-sub">Direct Extract</text>
                        </g>

                        {{-- Center Engine: SiteVaulta Encryption & Packaging Hub --}}
                        <g transform="translate(270, 140)">
                            <circle r="48" class="pv-hub" />
                            <circle r="46" class="pv-ring" />
                            <circle r="38" class="pv-ring-2" />
                            <circle r="28" fill="var(--surface)" stroke="var(--primary)" stroke-width="1.8" />
                            {{-- Center Padlock Icon --}}
                            <path d="M-6 2 L6 2 C7.1 2 8 2.9 8 4 L8 9 C8 10.1 7.1 11 6 11 L-6 11 C-7.1 11 -8 10.1 -8 9 L-8 4 C-8 2.9 -7.1 2 -6 2 Z" fill="var(--primary)" />
                            <path d="M-4 2 L-4 -2 C-4 -4.2 -2.2 -6 0 -6 C2.2 -6 4 -4.2 4 -2 L4 2" stroke="var(--primary)" stroke-width="2" stroke-linecap="round" fill="none" />
                            <circle cx="0" cy="6" r="1.5" fill="var(--surface)" />
                            <text x="0" y="64" text-anchor="middle" class="pv-text" style="font-size:12px;fill:var(--primary);">SiteVaulta Core</text>
                            <text x="0" y="77" text-anchor="middle" class="pv-sub">Zero-Plaintext Disk</text>
                        </g>

                        {{-- Destination Cloud Storage Nodes (Right) --}}
                        <g class="pv-store" transform="translate(416, 28)">
                            <rect class="pv-node-strong" width="112" height="42" rx="10" />
                            <text x="14" y="20" class="pv-text">Google Drive</text>
                            <text x="14" y="33" class="pv-sub">OAuth2 Token • BYOS</text>
                            <circle cx="98" cy="21" r="5" class="pv-check" />
                        </g>

                        <g class="pv-store s2" transform="translate(416, 88)">
                            <rect class="pv-node-strong" width="112" height="42" rx="10" />
                            <text x="14" y="20" class="pv-text">Amazon S3</text>
                            <text x="14" y="33" class="pv-sub">AWS Signature v4</text>
                            <circle cx="98" cy="21" r="5" class="pv-check" />
                        </g>

                        <g class="pv-store s3" transform="translate(416, 148)">
                            <rect class="pv-node-strong" width="112" height="42" rx="10" />
                            <text x="14" y="20" class="pv-text">Cloudflare R2</text>
                            <text x="14" y="33" class="pv-sub">Zero Egress Fees</text>
                            <circle cx="98" cy="21" r="5" class="pv-check" />
                        </g>

                        <g class="pv-store s4" transform="translate(416, 208)">
                            <rect class="pv-node-strong" width="112" height="42" rx="10" />
                            <text x="14" y="20" class="pv-text">Remote SFTP</text>
                            <text x="14" y="33" class="pv-sub">Dedicated VPS / NAS</text>
                            <circle cx="98" cy="21" r="5" class="pv-check" />
                        </g>
                    </svg>

                    {{-- Live Status Indicator Below Graphic --}}
                    <div class="pipeline-status">
                        <div class="status-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 12a9 9 0 0 1-9 9m9-9a9 9 0 0 0-9-9m9 9H3m9 9a9 9 0 0 1-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
                            </svg>
                        </div>
                        <div style="min-width:0;">
                            <div class="status-label">Initializing Backup Stream...</div>
                            <div class="status-meta">AES-256-GCM chunk encryption pipeline active</div>
                            <div class="progress" aria-hidden="true">
                                <i style="width: 25%;"></i>
                            </div>
                        </div>
                        <div class="status-pct">25%</div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Universal Integration Marquee
         ===================================================================== --}}
    <section class="marquee-section" aria-label="Supported Integrations and Platforms">
        <div class="marquee-title">Seamlessly Backs Up Any Tech Stack &bull; Streams to Any Cloud Destination</div>
        <div class="marquee">
            <div class="marquee-track">
                <span class="tech-chip"><i style="--c:#f43f5e;"></i> Laravel</span>
                <span class="tech-chip"><i style="--c:#2563eb;"></i> WordPress</span>
                <span class="tech-chip"><i style="--c:#22c55e;"></i> Node.js &amp; Express</span>
                <span class="tech-chip"><i style="--c:#0284c7;"></i> Django &amp; Python</span>
                <span class="tech-chip"><i style="--c:#f97316;"></i> cPanel &amp; WHM</span>
                <span class="tech-chip"><i style="--c:#eab308;"></i> MySQL &amp; MariaDB</span>
                <span class="tech-chip"><i style="--c:#3b82f6;"></i> PostgreSQL</span>
                <span class="tech-chip"><i style="--c:#10b981;"></i> Google Drive (OAuth)</span>
                <span class="tech-chip"><i style="--c:#f59e0b;"></i> Amazon S3</span>
                <span class="tech-chip"><i style="--c:#f97316;"></i> Cloudflare R2</span>
                <span class="tech-chip"><i style="--c:#06b6d4;"></i> DigitalOcean Spaces</span>
                <span class="tech-chip"><i style="--c:#8b5cf6;"></i> Custom SFTP / VPS</span>
                {{-- Duplicate items for seamless continuous marquee loop --}}
                <span class="tech-chip"><i style="--c:#f43f5e;"></i> Laravel</span>
                <span class="tech-chip"><i style="--c:#2563eb;"></i> WordPress</span>
                <span class="tech-chip"><i style="--c:#22c55e;"></i> Node.js &amp; Express</span>
                <span class="tech-chip"><i style="--c:#0284c7;"></i> Django &amp; Python</span>
                <span class="tech-chip"><i style="--c:#f97316;"></i> cPanel &amp; WHM</span>
                <span class="tech-chip"><i style="--c:#eab308;"></i> MySQL &amp; MariaDB</span>
                <span class="tech-chip"><i style="--c:#3b82f6;"></i> PostgreSQL</span>
                <span class="tech-chip"><i style="--c:#10b981;"></i> Google Drive (OAuth)</span>
                <span class="tech-chip"><i style="--c:#f59e0b;"></i> Amazon S3</span>
                <span class="tech-chip"><i style="--c:#f97316;"></i> Cloudflare R2</span>
                <span class="tech-chip"><i style="--c:#06b6d4;"></i> DigitalOcean Spaces</span>
                <span class="tech-chip"><i style="--c:#8b5cf6;"></i> Custom SFTP / VPS</span>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Key Operational Metrics & Stats
         ===================================================================== --}}
    <section class="container" style="margin-bottom: 72px;">
        <div class="stats" data-reveal="zoom">
            <div class="stat">
                <strong class="text-gradient">99.98%</strong>
                <span>Backup Success Rate</span>
            </div>
            <div class="stat">
                <strong class="text-gradient">0 MB</strong>
                <span>Permanent Plaintext on Disk</span>
            </div>
            <div class="stat">
                <strong class="text-gradient">Up to 80%</strong>
                <span>Storage Saved via Delta Chains</span>
            </div>
            <div class="stat">
                <strong class="text-gradient">&lt; 60 Sec</strong>
                <span>1-Click Restore Command</span>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         NEW Section: Interactive Live Uptime Radar, Read-Only Malware Scanner,
         Smart Email Auto-Fallback & White-Label Client Reports Showcase
         ===================================================================== --}}
    <section id="live-intelligence" class="section">
        <div class="container">
            <div class="section-head" data-reveal>
                <span class="kicker">More Than Just Backups • Complete Site Intelligence</span>
                <h2>24/7 Live Uptime Radar, Read-Only Malware Shield &amp; Smart Failover Alerts</h2>
                <p>Explore the interactive engine that monitors your domains, SSL certificates, server ports, and scripts—with a 100% Read-Only non-destructive guarantee.</p>
            </div>

            <div class="intel-showcase" data-reveal="zoom">
                <div class="intel-tabs" role="tablist" aria-label="SiteVaulta Intelligence Modules">
                    <button type="button" class="intel-tab" data-intel-tab="uptime" aria-selected="true">
                        <span>📡 1. Live Domain, Server &amp; SSL Radar</span>
                    </button>
                    <button type="button" class="intel-tab" data-intel-tab="malware" aria-selected="false">
                        <span>🛡️ 2. 100% Read-Only Malware Scanner</span>
                    </button>
                    <button type="button" class="intel-tab" data-intel-tab="email" aria-selected="false">
                        <span>📧 3. Smart Email Auto-Fallback &amp; Bell</span>
                    </button>
                    <button type="button" class="intel-tab" data-intel-tab="reports" aria-selected="false">
                        <span>📄 4. White-Label PDF &amp; Referral Wallet</span>
                    </button>
                </div>

                {{-- Pane 1: Live Domain, Server & SSL Expiry Radar --}}
                <div class="intel-pane is-active" data-intel-pane="uptime">
                    <div class="intel-grid">
                        <div>
                            <span class="kicker">Real-Time Infrastructure Telemetry</span>
                            <h3 style="font-size:1.6rem;margin:8px 0 12px;">Know the Second a Domain, Server, or SSL Certificate Has an Issue</h3>
                            <p style="color:var(--muted);margin-bottom:18px;">
                                Every verified website is checked automatically every 5 minutes across real DNS A-records, HTTP/HTTPS response latency, SSL port 443, and its connected server protocol (cPanel UAPI port 2083, SSH/SFTP port 22, or FTP port 21).
                            </p>
                            <ul class="check-list">
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>Multi-Protocol Server Health Probing</strong>
                                        <span>Verifies cPanel API (port 2083), SSH/SFTP (port 22), FTP (port 21), and MySQL/PostgreSQL reachability in real time.</span>
                                    </div>
                                </li>
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>SSL Certificate Expiry Auto-Reminders (30 / 14 / 7 / 3 Days)</strong>
                                        <span>Never let an expired HTTPS certificate scare away visitors—get automatic alerts before expiry.</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="radar-card">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                <strong style="color:#f8fafc;font-size:14px;">🌐 Site &amp; Server Intelligence — Live View</strong>
                                <span class="badge-pill ok"><span class="pulse-dot" style="width:6px;height:6px;"></span> Score: 100 / 100</span>
                            </div>
                            <div class="scan-beam"></div>
                            <div class="radar-row">
                                <span><strong>jtest.daffodil.university</strong> <small style="color:#94a3b8;">(A: 203.190.10.99)</small></span>
                                <span class="badge-pill ok">🟢 HTTP 200 • 42ms</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>SSL Certificate Guard</strong> <small style="color:#94a3b8;">(Let's Encrypt TLS 1.3)</small></span>
                                <span class="badge-pill ok">🔒 Valid • 78 Days Left</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>cPanel Server &amp; MySQL</strong> <small style="color:#94a3b8;">(Port 2083 • UAPI)</small></span>
                                <span class="badge-pill ok">🟢 Server Reachable</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>acme-commerce.example.com</strong> <small style="color:#94a3b8;">(Fake/Offline DNS)</small></span>
                                <span class="badge-pill crit">🔴 NXDOMAIN • Owner Alerted</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Pane 2: 100% Read-Only Malware, Web-Shell & .env Shield Scanner --}}
                <div class="intel-pane" data-intel-pane="malware">
                    <div class="intel-grid">
                        <div>
                            <span class="kicker">100% Read-Only Non-Destructive Guarantee</span>
                            <h3 style="font-size:1.6rem;margin:8px 0 12px;">Detect Web-Shells, Obfuscated Malware &amp; Exposed .env Files—With Zero Risk to Your Live Server</h3>
                            <p style="color:var(--muted);margin-bottom:18px;">
                                SiteVaulta <strong>never deletes, modifies, or writes any file on your live server</strong>. Our read-only diagnostic scanner inspects your server directories and backup snapshots, reporting the exact file path and line number so you stay in 100% control.
                            </p>
                            <ul class="check-list">
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>Web-Shell &amp; Backdoor Signature Engine</strong>
                                        <span>Catches <code>c99</code>, <code>r57</code>, <code>WSO</code>, <code>b374k</code>, <code>eval(base64_decode(...))</code>, and <code>shell_exec($_POST[...])</code> with exact file paths and line numbers.</span>
                                    </div>
                                </li>
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>Uploads Folder PHP Guard &amp; Public .env / .git Exposure Check</strong>
                                        <span>Flags executable <code>.php</code> scripts hidden inside <code>uploads/</code> and warns you if <code>/.env</code> or <code>/.git/config</code> is publicly accessible.</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="radar-card">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                <strong style="color:#f8fafc;font-size:14px;">🛡️ Read-Only Security &amp; Malware Diagnostic Report</strong>
                                <span class="badge-pill info">🔒 Zero Server Deletion</span>
                            </div>
                            <div class="scan-beam"></div>
                            <div class="radar-row">
                                <div>
                                    <div style="color:#f87171;font-weight:700;">[CRITICAL] Known Web-Shell (wso.php)</div>
                                    <code style="font-size:11.5px;color:#38bdf8;">/public_html/wp-content/uploads/2026/wso.php (Line 4)</code>
                                </div>
                                <span class="badge-pill crit">Reported Only</span>
                            </div>
                            <div class="radar-row">
                                <div>
                                    <div style="color:#fbbf24;font-weight:700;">[CRITICAL] Obfuscated eval(base64_decode)</div>
                                    <code style="font-size:11.5px;color:#38bdf8;">/public_html/wp-content/themes/header.php (Line 12)</code>
                                </div>
                                <span class="badge-pill warn">Exact Line #</span>
                            </div>
                            <div class="radar-row">
                                <div>
                                    <div style="color:#34d399;font-weight:700;">[SAFE] Public /.env &amp; /.git Exposure Shield</div>
                                    <small style="color:#94a3b8;">HTTP 403 Forbidden — Sensitive files properly blocked</small>
                                </div>
                                <span class="badge-pill ok">✓ Protected</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Pane 3: Smart Email Auto-Fallback & In-App Bell --}}
                <div class="intel-pane" data-intel-pane="email">
                    <div class="intel-grid">
                        <div>
                            <span class="kicker">Hybrid Notification Architecture</span>
                            <h3 style="font-size:1.6rem;margin:8px 0 12px;">Your Own Free Custom SMTP or System Email Wallet—With Automatic Failover</h3>
                            <p style="color:var(--muted);margin-bottom:18px;">
                                Set a custom notification email and individually toggle <strong>Notify on Success</strong> and <strong>Notify on Failure</strong> on every backup policy and website. Choose your preferred email channel and let our Smart Auto-Fallback guarantee delivery!
                            </p>
                            <ul class="check-list">
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>Smart Auto-Fallback (Custom SMTP ⇄ System Email Wallet)</strong>
                                        <span>If your selected channel (e.g., Custom SMTP or System Wallet Credits) is unavailable, SiteVaulta automatically switches to the other channel so you never miss a critical alert.</span>
                                    </div>
                                </li>
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>Real-Time In-App Database Notification Bell (🔔)</strong>
                                        <span>Every backup run, uptime transition, SSL reminder, and security report is persisted in your top-bar notification drawer with 15s live polling.</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="radar-card">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                <strong style="color:#f8fafc;font-size:14px;">📧 Smart Email Failover &amp; Per-Policy Controls</strong>
                                <span class="badge-pill ok">🛡️ Failover Active</span>
                            </div>
                            <div class="scan-beam"></div>
                            <div class="radar-row">
                                <span><strong>Option A: Own Custom SMTP</strong> <small style="color:#94a3b8;">(Any SMTP Host)</small></span>
                                <span class="badge-pill ok">100% Free &amp; Unlimited</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>Option B: System Email Digital Wallet</strong> <small style="color:#94a3b8;">(+{{ $welcomeFreeCredits ?? 50 }} Free Starter Credits)</small></span>
                                <span class="badge-pill info">1 Credit / Email</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>Per-Run &amp; Per-Policy Toggles</strong> <small style="color:#94a3b8;">(Success ON/OFF • Failed ON/OFF)</small></span>
                                <span class="badge-pill ok">Individual Control</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>In-App Database Notification Bell (🔔)</strong></span>
                                <span class="badge-pill ok">Always Delivered Free</span>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- Pane 4: White-Label PDF Reports & Referral Digital Wallet --}}
                <div class="intel-pane" data-intel-pane="reports">
                    <div class="intel-grid">
                        <div>
                            <span class="kicker">Built for Agencies &amp; Growth</span>
                            <h3 style="font-size:1.6rem;margin:8px 0 12px;">1-Click White-Label Client PDF Reports &amp; Referral Credit Rewards</h3>
                            <p style="color:var(--muted);margin-bottom:18px;">
                                Impress your clients every month with branded A4 PDF maintenance &amp; security audit reports—and earn hundreds of free System Email credits automatically when you refer fellow developers or agencies!
                            </p>
                            <ul class="check-list">
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>White-Label Client Executive PDF Reports (7 / 30 / 90 Days)</strong>
                                        <span>Includes your Agency Name, Logo, Brand Color, Protection Score (0–100), SSL Countdown, Read-Only Malware Audit, and Verified Backup Log.</span>
                                    </div>
                                </li>
                                <li>
                                    <div class="ci"><svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg></div>
                                    <div>
                                        <strong>Earn Free Email Credits via Referrals</strong>
                                        <span>Get <strong>+{{ $referrerSignupBonus ?? 25 }} Credits</strong> on signup and <strong>+{{ $referrerPurchaseReward ?? 500 }} Credits</strong> when your referred user purchases any plan or package (plus they get <strong>+{{ $refereePurchaseBonus ?? 200 }} Bonus Credits</strong>!).</span>
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <div class="radar-card">
                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                                <strong style="color:#f8fafc;font-size:14px;">🎁 Digital Wallet &amp; Agency PDF Report Preview</strong>
                                <span class="badge-pill ok">A4 PDF Ready</span>
                            </div>
                            <div class="scan-beam"></div>
                            <div class="radar-row">
                                <span><strong>Welcome Starter Bonus</strong> <small style="color:#94a3b8;">(Every New Workspace)</small></span>
                                <span class="badge-pill ok">+{{ $welcomeFreeCredits ?? 50 }} Free Credits</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>Referral Signup + Purchase Reward</strong></span>
                                <span class="badge-pill info">+{{ ($referrerSignupBonus ?? 25) + ($referrerPurchaseReward ?? 500) }} Credits / Referral</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>Referee Welcome Purchase Bonus</strong></span>
                                <span class="badge-pill ok">+{{ $refereePurchaseBonus ?? 200 }} Bonus Credits</span>
                            </div>
                            <div class="radar-row">
                                <span><strong>White-Label Client Report</strong> <small style="color:#94a3b8;">(Custom Logo &amp; Footer)</small></span>
                                <span class="badge-pill ok">📄 1-Click PDF Download</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Section 1: How It Works (3 Simple Steps)
         ===================================================================== --}}
    <section id="how-it-works" class="section section-alt">
        <div class="container">
            <div class="section-head" data-reveal>
                <span class="kicker">Effortless Automation</span>
                <h2>Three Steps to Zero-Loss Peace of Mind</h2>
                <p>Never worry about server crashes, accidental database drops, ransomware or broken updates again.</p>
            </div>

            <div class="steps">
                <div class="steps-line" aria-hidden="true"></div>

                <div class="step" data-reveal style="--d: 0.1s;">
                    <div class="step-num">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        <b>1</b>
                    </div>
                    <h3>Verify &amp; Connect Origin</h3>
                    <p>Verify domain ownership via DNS TXT or Meta Tag (SSRF Guard protected). Connect server via SSH/SFTP or cPanel API.</p>
                </div>

                <div class="step" data-reveal style="--d: 0.2s;">
                    <div class="step-num">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M19 11H5m14 0a2 2 0 0 1 2 2v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-6a2 2 0 0 1 2-2m14 0V9a2 2 0 0 0-2-2M5 11V9a2 2 0 0 1 2-2m0 0V5a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v2M7 7h10"/></svg>
                        <b>2</b>
                    </div>
                    <h3>Bring Your Own Cloud (BYOS)</h3>
                    <p>Connect your own Google Drive (1-click OAuth), AWS S3, Cloudflare R2, or VPS storage. You hold the encryption keys.</p>
                </div>

                <div class="step" data-reveal style="--d: 0.3s;">
                    <div class="step-num">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 8v4l3 3m6-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                        <b>3</b>
                    </div>
                    <h3>Set Schedule &amp; Relax</h3>
                    <p>Choose automated schedules or trigger via GitHub Actions Webhooks before deployments. Get instant alerts via Telegram, Slack, or Email.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Section 2: Interactive Pre-Backup Storage Forecaster
         ===================================================================== --}}
    <section id="forecast" class="section">
        <div class="container">
            <div class="section-head" data-reveal>
                <span class="kicker">Live Interactive Tool</span>
                <h2>Pre-Backup Storage &amp; Bandwidth Forecaster</h2>
                <p>Calculate your exact cloud storage footprint, peak overlap, and incremental delta savings before activating any schedule.</p>
            </div>

            <div class="forecast" data-reveal="zoom">
                {{-- Controls Panel --}}
                <div class="panel">
                    <h3 style="font-size:1.3rem;margin-bottom:20px;">Backup Policy Parameters</h3>

                    <div class="control">
                        <div class="control-head">
                            <label for="fc-size">Source Website &amp; Database Size</label>
                            <output id="fc-size-out">25 GB</output>
                        </div>
                        <input type="range" id="fc-size" min="2" max="250" value="25" step="1" aria-label="Source website and database size in gigabytes">
                    </div>

                    <div class="control">
                        <div class="control-head">
                            <label for="fc-retention">Retention Window</label>
                            <output id="fc-retention-out">14 Days</output>
                        </div>
                        <input type="range" id="fc-retention" min="3" max="60" value="14" step="1" aria-label="Retention window in days">
                    </div>

                    <div class="control">
                        <div class="control-head">
                            <span>Backup Strategy</span>
                        </div>
                        <div class="seg seg-strategy" role="group" aria-label="Select backup strategy">
                            <button type="button" data-strategy="incremental" aria-pressed="true">Chain Incremental</button>
                            <button type="button" data-strategy="full" aria-pressed="false">Standalone Full Only</button>
                        </div>
                    </div>

                    <div class="fine-print">
                        * Calculations simulate zstd compression factor (0.6x) and daily change delta rate (5%) based on SiteVaulta's production engine.
                    </div>
                </div>

                {{-- Live Results Panel --}}
                <div class="panel" style="background:var(--surface-2);display:flex;flex-direction:column;justify-content:center;">
                    <div style="font-size:13px;font-weight:700;color:var(--primary);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:8px;">Projected Peak Storage</div>
                    <div class="result-big text-gradient" id="fc-result-peak">42.5 GB</div>
                    <div class="result-label">Total storage occupied on your cloud destination at steady state.</div>

                    <div class="bars">
                        <div class="bar-row">
                            <div class="bar-head">
                                <span>Traditional Naive Daily Full Backups</span>
                                <b id="fc-bar-full-text">210.0 GB</b>
                            </div>
                            <div class="bar full"><i id="fc-bar-full" style="width:100%;"></i></div>
                        </div>

                        <div class="bar-row">
                            <div class="bar-head">
                                <span style="color:var(--primary);font-weight:700;">SiteVaulta Smart Incremental Chain</span>
                                <b id="fc-bar-smart-text">42.5 GB</b>
                            </div>
                            <div class="bar smart"><i id="fc-bar-smart" style="width:20%;"></i></div>
                        </div>
                    </div>

                    <div>
                        <span class="saving-pill" id="fc-saving-pct">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Save ~80% Cloud Storage &amp; Bandwidth</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Section 3: Bento Feature Grid
         ===================================================================== --}}
    <section id="features" class="section section-alt">
        <div class="container">
            <div class="section-head" data-reveal>
                <span class="kicker">Engineered for Developers &amp; Agencies</span>
                <h2>Everything Required for Bulletproof Backups</h2>
                <p>Designed from the ground up for agencies, hosting providers, and developers managing multiple production servers.</p>
            </div>

            <div class="bento">
                <div class="feature wide" data-reveal style="--d:0.05s;">
                    <div class="f-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                    </div>
                    <h3>Mandatory Domain Ownership &amp; SSRF Guard</h3>
                    <p>Prevents unauthorized scraping or targeting unintended internal infrastructure. Blocks RFC1918 private IP subnets and validates ownership via DNS TXT, HTML file, or meta tags.</p>
                    <div class="tags">
                        <span>DNS TXT Record</span>
                        <span>SSRF Protected</span>
                        <span>Anti-Scraping</span>
                    </div>
                </div>

                <div class="feature" data-reveal style="--d:0.1s;">
                    <div class="f-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                    </div>
                    <h3>Zero Plaintext on Disk</h3>
                    <p>Encrypted in chunks using military-grade AES-256-GCM. Staged ephemerally in volatile storage and purged instantly upon completion.</p>
                    <div class="tags">
                        <span>AES-256-GCM</span>
                        <span>Ephemeral /tmp</span>
                    </div>
                </div>

                <div class="feature" data-reveal style="--d:0.15s;">
                    <div class="f-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="16 18 22 12 16 6"></polyline><polyline points="8 6 2 12 8 18"></polyline></svg>
                    </div>
                    <h3>Pre-Deployment Webhook API</h3>
                    <p>Trigger automated backups directly from GitHub Actions, GitLab CI/CD, or deployment scripts before deploying any risky changes.</p>
                    <div class="tags">
                        <span>CI/CD Webhook</span>
                        <span>One-Click Revert</span>
                    </div>
                </div>

                <div class="feature" data-reveal style="--d:0.2s;">
                    <div class="f-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 14 14"></polyline></svg>
                    </div>
                    <h3>Chain-Safe Retention Pruning</h3>
                    <p>Saves storage with incremental deltas while guaranteeing base full backups are never deleted until all downstream increments expire.</p>
                    <div class="tags">
                        <span>No Broken Chains</span>
                        <span>Auto-Prune</span>
                    </div>
                </div>

                <div class="feature wide" data-reveal style="--d:0.25s;">
                    <div class="f-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="7 10 12 15 17 10"></polyline><line x1="12" y1="15" x2="12" y2="3"></line></svg>
                    </div>
                    <h3>Atomic One-Time Downloads &amp; Context Restore Guide</h3>
                    <p>Single-use cryptographic download tokens prevent link re-use or unauthorized access. Every backup run generates copy-paste ready CLI and cPanel restore commands tailored to that exact archive.</p>
                    <div class="tags">
                        <span>Single-Use Token</span>
                        <span>CLI / cPanel Guide</span>
                        <span>SHA-256 Verified</span>
                    </div>
                </div>

                <div class="feature" data-reveal style="--d:0.3s;">
                    <div class="f-icon" style="color:#f59e0b;background:rgba(245,158,11,0.12);border-color:rgba(245,158,11,0.28);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    </div>
                    <h3>100% Read-Only Malware &amp; Web-Shell Scanner</h3>
                    <p>Detects obfuscated PHP backdoors (`eval(base64_decode)`), web-shells (`WSO`, `c99`, `r57`), crypto-miners, and publicly exposed `.env` / `.git` / `.sql` files with a strict zero-deletion guarantee.</p>
                    <div class="tags">
                        <span>100% Non-Destructive</span>
                        <span>Exact File Path Report</span>
                        <span>Zero Live Edits</span>
                    </div>
                </div>

                <div class="feature" data-reveal style="--d:0.35s;">
                    <div class="f-icon" style="color:#06b6d4;background:rgba(6,182,212,0.12);border-color:rgba(6,182,212,0.28);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </div>
                    <h3>24/7 Live Uptime, Multi-Protocol Server &amp; SSL Radar</h3>
                    <p>Continuous HTTP/HTTPS response checks, DNS A-record lookups, TLS/SSL expiry countdowns (`30/14/7/3` days), and live server probes across SSH/SFTP, cPanel (`:2083`), FTP, and DB sockets.</p>
                    <div class="tags">
                        <span>SSL Expiry Guard</span>
                        <span>0–100 Health Score</span>
                        <span>cPanel &amp; SSH Probe</span>
                    </div>
                </div>

                <div class="feature" data-reveal style="--d:0.4s;">
                    <div class="f-icon" style="color:#a855f7;background:rgba(168,85,247,0.12);border-color:rgba(168,85,247,0.28);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                    </div>
                    <h3>Smart Email Auto-Fallback &amp; In-App Bell</h3>
                    <p>Automatically fail-over between Free Custom SMTP and System Email Digital Wallet so critical downtime or backup failure alerts are never dropped—plus real-time in-app database bell notifications (`🔔`).</p>
                    <div class="tags">
                        <span>SMTP ⇄ Wallet Failover</span>
                        <span>Per-Run Toggles</span>
                        <span>In-App Bell 🔔</span>
                    </div>
                </div>

                <div class="feature" data-reveal style="--d:0.45s;">
                    <div class="f-icon" style="color:#10b981;background:rgba(16,185,129,0.12);border-color:rgba(16,185,129,0.28);">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <h3>White-Label Client PDF Reports &amp; Referral Wallet</h3>
                    <p>Generate 1-click executive maintenance &amp; security audit PDFs branded with your agency logo, and earn automatic free System Email Credits whenever referred workspaces register or upgrade.</p>
                    <div class="tags">
                        <span>Client Executive PDF</span>
                        <span>Agency Branding</span>
                        <span>Referral Rewards</span>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Section 4: Interactive Developer Terminal & Security Showcase
         ===================================================================== --}}
    <section class="section">
        <div class="container split">
            <div data-reveal="left">
                <span class="kicker">Developer First</span>
                <h2>Zero Complexity When Disaster Strikes</h2>
                <p style="color:var(--muted);margin-top:12px;font-size:1.05rem;">
                    Backups are only as good as the restore process. SiteVaulta ensures you never struggle with complex manual decompression or lost database passwords.
                </p>

                <ul class="check-list">
                    <li>
                        <div class="ci">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                        </div>
                        <div>
                            <strong>Instant One-Command Database Restore</strong>
                            <span>Single piped stream decompresses and imports directly into MySQL / PostgreSQL with zero temporary storage required.</span>
                        </div>
                    </li>
                    <li>
                        <div class="ci">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                        </div>
                        <div>
                            <strong>100% Read-Only Malware &amp; Uptime Diagnostics</strong>
                            <span>Scan server directories and live endpoints for web-shells or `.env` leaks without ever deleting or altering a single byte on your server.</span>
                        </div>
                    </li>
                    <li>
                        <div class="ci">
                            <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                        </div>
                        <div>
                            <strong>Agency White-Label Client PDF Reports</strong>
                            <span>Generate branded verification &amp; security audit reports with your company logo and custom colors for end-clients.</span>
                        </div>
                    </li>
                </ul>
            </div>

            {{-- Interactive Terminal Emulator --}}
            <div data-reveal="right">
                <div class="terminal">
                    <div class="terminal-bar">
                        <div class="window-dots" aria-hidden="true">
                            <i></i><i></i><i></i>
                        </div>
                        <span>bash — sitevaulta-cli</span>
                        <div style="margin-left:auto;display:flex;gap:6px;flex-wrap:wrap;">
                            <div class="seg" style="padding:2px;background:rgba(255,255,255,0.06);">
                                <button type="button" data-term-tab="restore" aria-pressed="true" style="padding:4px 8px;font-size:11px;color:#f8fafc;">1-Click Restore</button>
                                <button type="button" data-term-tab="security" aria-pressed="false" style="padding:4px 8px;font-size:11px;color:#f8fafc;">Malware &amp; Uptime</button>
                                <button type="button" data-term-tab="webhook" aria-pressed="false" style="padding:4px 8px;font-size:11px;color:#f8fafc;">CI/CD Webhook</button>
                                <button type="button" data-term-tab="verify" aria-pressed="false" style="padding:4px 8px;font-size:11px;color:#f8fafc;">SSRF &amp; Verify</button>
                            </div>
                        </div>
                    </div>
                    <pre><code id="terminal-content"><span class="t-comment"># 1. Download atomic one-time encrypted archive</span>
<span class="t-prompt">$</span> curl -sSL "https://sitevaulta.app/download/one-time/sv_token_9f82d1c" -o backup.enc.zip
<span class="t-comment"># 2. Verify SHA-256 integrity hash before extraction</span>
<span class="t-prompt">$</span> sha256sum -c backup.enc.zip.sha256
<span class="t-ok">backup.enc.zip: OK (Checksum Matches e3b0c44298fc1c149afbf4c8996fb92427ae41e4649)</span>
<span class="t-comment"># 3. Decrypt AES-256-GCM &amp; restore database in single command</span>
<span class="t-prompt">$</span> unzip -p backup.enc.zip database/db-dump.sql.gz | gunzip | mysql -u user -p db_name
<span class="t-ok">✓ 142 tables &amp; 3.8 GB web root restored successfully in 42 seconds!</span><span class="cursor"></span></code></pre>
                </div>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Section 5: Dynamic Multi-Currency Pricing
         ===================================================================== --}}
    <section id="pricing" class="section section-alt">
        <div class="container">
            <div class="section-head" data-reveal>
                <span class="kicker">Transparent Pricing</span>
                <h2>Simple, Predictable Plans for Any Scale</h2>
                <p>Pay locally via SSLCOMMERZ (bKash, Nagad, Cards) in BDT ৳ or globally via Stripe &amp; PayPal in USD $.</p>
            </div>

            {{-- Interactive Pricing Switchers --}}
            <div class="pricing-toggles" data-reveal="zoom">
                {{-- Currency Switcher --}}
                <div class="toggle-group" role="group" aria-label="Select Currency">
                    <button type="button" data-currency-btn="BDT" aria-pressed="true">
                        <span>৳ BDT (Bangladesh)</span>
                    </button>
                    <button type="button" data-currency-btn="USD" aria-pressed="false">
                        <span>$ USD (Global)</span>
                    </button>
                </div>

                {{-- Billing Cycle Switcher --}}
                <div class="toggle-group" role="group" aria-label="Select Billing Cycle">
                    <button type="button" data-cycle-btn="monthly" aria-pressed="true">
                        <span>Monthly</span>
                    </button>
                    <button type="button" data-cycle-btn="yearly" aria-pressed="false">
                        <span>Yearly</span>
                        <span class="save-badge">Save up to 20%</span>
                    </button>
                </div>
            </div>

            {{-- Plan Cards --}}
            @if($plans->isNotEmpty())
                <div class="plans" data-reveal>
                    @foreach($plans as $plan)
                        @php
                            $discountPct = $plan->getYearlyDiscountPercent();
                            $bdtMonthly = (float) $plan->price_bdt_monthly;
                            $bdtYearly = $plan->getYearlyPrice('BDT');
                            $usdMonthly = (float) $plan->price_usd_monthly;
                            $usdYearly = $plan->getYearlyPrice('USD');
                        @endphp
                        <div class="plan {{ $plan->is_featured ? 'featured' : '' }}"
                             data-plan-id="{{ $plan->id }}"
                             data-bdt-monthly="{{ $bdtMonthly }}"
                             data-bdt-yearly="{{ $bdtYearly }}"
                             data-usd-monthly="{{ $usdMonthly }}"
                             data-usd-yearly="{{ $usdYearly }}"
                             data-discount-pct="{{ $discountPct }}">

                            @if($plan->is_featured)
                                <div class="ribbon">Most Popular Choice</div>
                            @endif

                            <h3>{{ $plan->name }}</h3>
                            <div class="plan-desc">{{ $plan->description }}</div>

                            <div class="price">
                                <div class="amount">৳{{ number_format($bdtMonthly, 0) }}</div>
                                <div class="per">/ month</div>
                            </div>

                            <div class="price-note">
                                @if($discountPct > 0)
                                    Or ৳{{ number_format($bdtYearly, 0) }}/yr with yearly billing
                                @endif
                            </div>

                            <ul>
                                <li>
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    <span><strong>{{ $plan->max_websites }}</strong> Verified Websites</span>
                                </li>
                                <li>
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    <span><strong>{{ $plan->max_policies }}</strong> Automated Backup Policies</span>
                                </li>
                                <li>
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    <span><strong>{{ $plan->max_storage_connections }}</strong> Cloud Storage Destinations</span>
                                </li>
                                <li>
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    <span><strong>{{ $plan->max_team_members }}</strong> Workspace Team Members</span>
                                </li>
                                <li>
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    <span><strong>{{ $plan->max_retention_days }} Days</strong> Retention History</span>
                                </li>
                                <li>
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    <span><strong>24/7 Live Uptime, SSL &amp; Read-Only Malware Radar</strong></span>
                                </li>
                                <li>
                                    <svg viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                    <span><strong>{{ number_format((int) ($welcomeFreeCredits ?? 50)) }} Free System Email Credits</strong> + Free Custom SMTP</span>
                                </li>
                            </ul>

                            <a href="/app/register" class="btn {{ $plan->is_featured ? 'btn-primary' : 'btn-ghost' }}">
                                <span>Start {{ $trialDays }}-Day Trial</span>
                                <span class="arrow" aria-hidden="true">&rarr;</span>
                            </a>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="empty-plans">
                    <p>Pricing packages are loading from the database...</p>
                </div>
            @endif

            {{-- Smart Email Alert Channels & Optional Digital Wallet Top-Ups --}}
            <div class="radar-card" style="margin-top:42px;padding:28px;" data-reveal>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;margin-bottom:20px;">
                    <div>
                        <span class="kicker" style="margin-bottom:6px;">Flexible Alert Routing &amp; Digital Wallet</span>
                        <h3 style="font-size:1.35rem;margin-top:4px;">📧 Free Custom SMTP + Never-Expiring System Email Credit Packs</h3>
                        <p style="color:var(--muted);font-size:0.95rem;margin-top:4px;">
                            Use your own SMTP server for <strong>100% Free Unlimited Emails</strong>, or use our instant System Email Digital Wallet (1 Credit = 1 Email) with automatic smart failover. Every new workspace starts with <strong>{{ number_format((int) ($welcomeFreeCredits ?? 50)) }} Free Welcome Credits</strong>!
                        </p>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span class="badge-pill ok">🎁 +{{ number_format((int) ($welcomeFreeCredits ?? 50)) }} Free Welcome Credits</span>
                        <span class="badge-pill info">🤝 +{{ number_format((int) ($referrerSignupBonus ?? 50)) }} Referral Signup Bonus</span>
                    </div>
                </div>

                @if(isset($emailPackages) && $emailPackages->isNotEmpty())
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
                        @foreach($emailPackages as $pkg)
                            <div style="padding:16px;border-radius:14px;background:var(--bg-elev);border:1px solid {{ $pkg->is_featured ? 'var(--primary)' : 'var(--border)' }};position:relative;">
                                @if($pkg->is_featured)
                                    <span class="badge-pill ok" style="position:absolute;top:12px;right:12px;font-size:10px;">Best Value</span>
                                @endif
                                <div style="font-weight:800;font-size:1rem;color:var(--text);">{{ $pkg->name }}</div>
                                <div style="font-size:1.35rem;font-weight:800;color:var(--primary);margin:6px 0;">
                                    {{ number_format($pkg->credits) }} <span style="font-size:0.8rem;font-weight:600;color:var(--muted);">Credits</span>
                                </div>
                                <div style="font-size:0.86rem;font-weight:700;color:var(--text);">
                                    ৳{{ number_format((float) $pkg->price_bdt, 0) }} BDT <span style="color:var(--muted);font-weight:500;">/ ${{ number_format((float) $pkg->price_usd, 2) }} USD</span>
                                </div>
                                <div style="font-size:0.78rem;color:var(--muted);margin-top:6px;">
                                    Never expires • Instant auto-fallback ready
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="payments">
                <span>Supported Gateways &amp; Methods:</span>
                <span class="pm">bKash</span>
                <span class="pm">Nagad</span>
                <span class="pm">Rocket</span>
                <span class="pm">Visa / Mastercard</span>
                <span class="pm">SSLCOMMERZ</span>
                <span class="pm">Stripe</span>
                <span class="pm">PayPal</span>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Section 6: Frequently Asked Questions (FAQ)
         ===================================================================== --}}
    <section id="faq" class="section">
        <div class="container">
            <div class="section-head" data-reveal>
                <span class="kicker">Got Questions?</span>
                <h2>Frequently Asked Questions</h2>
                <p>Everything you need to know about BYOS cloud storage, read-only security scanning, smart alerts, and encryption.</p>
            </div>

            <div class="faq" data-reveal>
                <details open>
                    <summary>
                        <span>How does "Bring Your Own Storage (BYOS)" work?</span>
                        <div class="chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </div>
                    </summary>
                    <div class="answer">
                        Unlike traditional backup services that charge hefty recurring markups for storage, SiteVaulta streams encrypted backup archives directly into your own Google Drive, Amazon S3, Cloudflare R2, or remote VPS. If you ever cancel your subscription, <strong>all your historical backups remain 100% in your possession</strong> with zero lock-in.
                    </div>
                </details>

                <details>
                    <summary>
                        <span>Will the Malware &amp; Web-Shell Scanner ever delete or modify files on my live server?</span>
                        <div class="chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </div>
                    </summary>
                    <div class="answer">
                        <strong>100% Never.</strong> Our Malware, Web-Shell &amp; Exposure Scanner is strictly engineered to be <strong>100% Read-Only and Non-Destructive</strong>. There is no auto-delete or manual file deletion function in our system. If a suspicious script or exposed `.env` / `.sql` file is detected, we report the exact file path, signature category, and line context so your developers can safely inspect it without any risk of breaking your live production site.
                    </div>
                </details>

                <details>
                    <summary>
                        <span>How does Smart Email Delivery Auto-Fallback work?</span>
                        <div class="chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </div>
                    </summary>
                    <div class="answer">
                        You can configure your own Custom SMTP server to send unlimited alert emails for free, or use your System Email Digital Wallet (starting with {{ number_format((int) ($welcomeFreeCredits ?? 50)) }} free credits). With <strong>Smart Auto-Fallback</strong> enabled, if your Custom SMTP server goes down, automatically switches to your System Email Wallet—and if your wallet reaches 0 credits, it seamlessly falls back to your verified Custom SMTP. Every alert is also saved in your real-time In-App Notification Bell (`🔔`).
                    </div>
                </details>

                <details>
                    <summary>
                        <span>Do you store my database passwords or unencrypted files?</span>
                        <div class="chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </div>
                    </summary>
                    <div class="answer">
                        <strong>Never.</strong> Credentials are encrypted in our database using Laravel's AES-256 encryption. Backup archives are packaged and encrypted with AES-256-GCM in an ephemeral staging folder (`/tmp/sitevaulta`) and instantly purged the millisecond the upload is confirmed. Zero unencrypted customer data ever touches persistent storage.
                    </div>
                </details>

                <details>
                    <summary>
                        <span>How does Mandatory Domain Verification protect my server?</span>
                        <div class="chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </div>
                    </summary>
                    <div class="answer">
                        To prevent Server-Side Request Forgery (SSRF) and unauthorized scraping, you must verify that you legitimately control the target website before any backup connector or schedule can be activated. Verification can be performed via a DNS TXT record, an HTML verification file in your web root, or a meta tag on your homepage.
                    </div>
                </details>

                <details>
                    <summary>
                        <span>What happens when my free trial period ends?</span>
                        <div class="chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </div>
                    </summary>
                    <div class="answer">
                        You get full unrestricted access to all features for {{ $trialDays }} days with no credit card required at registration. When your trial ends, you can easily choose a monthly or yearly plan and complete payment via local payment gateways (bKash/Nagad/Cards via SSLCOMMERZ) or global gateways (Stripe/PayPal).
                    </div>
                </details>

                <details>
                    <summary>
                        <span>Can I trigger backups automatically before git deploys?</span>
                        <div class="chev" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
                        </div>
                    </summary>
                    <div class="answer">
                        Yes! Each verified website comes with a unique, secure Pre-Deployment Webhook API URL. You can invoke this webhook in your GitHub Actions, GitLab CI/CD, or deployment scripts to guarantee a fresh backup is safely stored before any code change goes live.
                    </div>
                </details>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Section 7: Final Conversion Call to Action
         ===================================================================== --}}
    <section class="container" style="margin-top: 40px;">
        <div class="cta-band" data-reveal="zoom">
            <h2>Ready to Secure Your Websites Forever?</h2>
            <p>Connect your first server in under 3 minutes. Zero vendor lock-in, zero credit card required to start.</p>
            <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
                <a href="/app/register" class="btn btn-white btn-lg">
                    <span>Start {{ $trialDays }}-Day Free Trial</span>
                    <span class="arrow" aria-hidden="true">&rarr;</span>
                </a>
                <a href="/docs" class="btn btn-outline-white btn-lg">Read Technical Docs</a>
            </div>
        </div>
    </section>

    {{-- =====================================================================
         Shared Main UI Site Footer
         ===================================================================== --}}
    @include('partials.public-footer')

    {{-- Interactive Landing Page JavaScript --}}
    <script src="{{ asset('assets/landing/landing.js') }}"></script>
</body>
</html>
