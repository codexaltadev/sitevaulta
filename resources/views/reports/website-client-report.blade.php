<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Executive Maintenance & Security Report — {{ $website->name }} ({{ $website->domain }})</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: DejaVu Sans, Inter, Helvetica, Arial, sans-serif;
            margin: 0;
            padding: {{ $isWebPreview ? '24px' : '0' }};
            background: {{ $isWebPreview ? '#f1f5f9' : '#ffffff' }};
            color: #0f172a;
            font-size: 12px;
            line-height: 1.5;
        }
        .report-container {
            max-width: 900px;
            margin: 0 auto;
            background: #ffffff;
            border: {{ $isWebPreview ? '1px solid #cbd5e1' : 'none' }};
            border-radius: {{ $isWebPreview ? '12px' : '0' }};
            padding: {{ $isWebPreview ? '36px' : '20px' }};
            box-shadow: {{ $isWebPreview ? '0 10px 30px rgba(15, 23, 42, 0.08)' : 'none' }};
        }
        .toolbar {
            max-width: 900px;
            margin: 0 auto 18px auto;
            padding: 14px 20px;
            background: #0f172a;
            color: #f8fafc;
            border-radius: 10px;
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }
        .btn {
            display: inline-block;
            padding: 8px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 700;
            font-size: 12px;
            cursor: pointer;
            border: none;
        }
        .btn-primary { background: #0d9488; color: #ffffff; }
        .btn-secondary { background: #334155; color: #f8fafc; }
        .btn-outline { background: transparent; border: 1px solid #475569; color: #e2e8f0; }
        .header-bar {
            border-bottom: 3px solid {{ $agencyColor ?: '#0d9488' }};
            padding-bottom: 16px;
            margin-bottom: 20px;
        }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; }
        .badge-online {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #dcfce7;
            color: #166534;
            font-weight: 700;
            font-size: 11px;
        }
        .badge-down {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            background: #fee2e2;
            color: #991b1b;
            font-weight: 700;
            font-size: 11px;
        }
        .kpi-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 8px;
            margin: 0 -8px 20px -8px;
        }
        .kpi-card {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            width: 25%;
            vertical-align: top;
        }
        .kpi-label {
            font-size: 10px;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .kpi-value {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }
        .kpi-sub {
            font-size: 10.5px;
            color: #475569;
            margin-top: 3px;
        }
        .section-box {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 14px 16px;
            margin-bottom: 18px;
            background: #ffffff;
        }
        .section-title {
            font-size: 13px;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 10px 0;
            padding-bottom: 6px;
            border-bottom: 1px solid #e2e8f0;
        }
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11.5px;
        }
        .data-table th {
            background: #f1f5f9;
            color: #334155;
            text-align: left;
            padding: 7px 10px;
            font-size: 10.5px;
            text-transform: uppercase;
            border-bottom: 1px solid #cbd5e1;
        }
        .data-table td {
            padding: 7px 10px;
            border-bottom: 1px solid #e2e8f0;
            color: #1e293b;
        }
        .footer-box {
            margin-top: 24px;
            padding-top: 14px;
            border-top: 1px solid #cbd5e1;
            text-align: center;
            color: #64748b;
            font-size: 11px;
        }
        @media print {
            body { background: #ffffff; padding: 0; }
            .toolbar { display: none !important; }
            .report-container { border: none; box-shadow: none; padding: 0; max-width: 100%; }
        }
    </style>
</head>
<body>

@if($isWebPreview)
    <div class="toolbar">
        <div>
            <strong style="font-size:14px;">📄 White-Label Client Maintenance &amp; Security Report</strong>
            <span style="color:#94a3b8;font-size:12px;margin-left:8px;">({{ $website->domain }})</span>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
            <a href="{{ route('websites.client-report.view', ['workspaceSlug' => $workspace->slug, 'websiteUuid' => $website->uuid, 'period' => 7]) }}"
               class="btn {{ $periodDays === 7 ? 'btn-primary' : 'btn-outline' }}">7 Days</a>
            <a href="{{ route('websites.client-report.view', ['workspaceSlug' => $workspace->slug, 'websiteUuid' => $website->uuid, 'period' => 30]) }}"
               class="btn {{ $periodDays === 30 ? 'btn-primary' : 'btn-outline' }}">30 Days</a>
            <a href="{{ route('websites.client-report.view', ['workspaceSlug' => $workspace->slug, 'websiteUuid' => $website->uuid, 'period' => 90]) }}"
               class="btn {{ $periodDays === 90 ? 'btn-primary' : 'btn-outline' }}">90 Days</a>

            <a href="{{ route('websites.client-report.pdf', ['workspaceSlug' => $workspace->slug, 'websiteUuid' => $website->uuid, 'period' => $periodDays]) }}"
               class="btn btn-primary">⬇️ Download PDF Report</a>

            <button onclick="window.print()" class="btn btn-secondary">🖨️ Print</button>

            <a href="/app/{{ $workspace->slug }}/profile" class="btn btn-outline">🎨 Customize Branding</a>
        </div>
    </div>
@endif

<div class="report-container">
    <!-- White-Label Agency Header -->
    <div class="header-bar">
        <table class="header-table">
            <tr>
                <td style="width:60%;">
                    @if(!empty($agencyLogoUrl) && $isWebPreview)
                        <img src="{{ $agencyLogoUrl }}" alt="{{ $agencyName }}" style="max-height:42px;margin-bottom:6px;display:block;">
                    @endif
                    <div style="font-size:20px;font-weight:900;color:{{ $agencyColor ?: '#0d9488' }};">{{ $agencyName }}</div>
                    <div style="font-size:13px;font-weight:700;color:#1e293b;margin-top:2px;">
                        Website Backup, Uptime &amp; Read-Only Security Audit Report
                    </div>
                    <div style="font-size:11px;color:#64748b;margin-top:2px;">
                        Prepared for: <strong>{{ $website->name }}</strong> ({{ $website->domain }}) • Environment: {{ strtoupper((string) $website->environment) }}
                    </div>
                </td>
                <td style="width:40%;text-align:right;">
                    <div style="font-size:11px;color:#64748b;">Report Reference: <strong>{{ $reportReference }}</strong></div>
                    <div style="font-size:11px;color:#64748b;">Reporting Window: <strong>Last {{ $periodDays }} Days</strong></div>
                    <div style="font-size:11px;color:#64748b;">Generated On: <strong>{{ now()->format('d M Y, H:i') }} UTC</strong></div>
                    <div style="margin-top:6px;">
                        @if($website->uptime_status === 'online')
                            <span class="badge-online">ONLINE &amp; PROTECTED (HTTP {{ $website->last_http_status_code ?: 200 }})</span>
                        @else
                            <span class="badge-down">ATTENTION REQUIRED ({{ strtoupper((string) $website->uptime_status) }})</span>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    <!-- 4 Executive KPI Cards -->
    @php
        $ssl = $intel['ssl'];
        $filesMb = number_format(((int) $website->known_files_size_bytes) / (1024 * 1024), 1);
        $dbMb = number_format(((int) $website->known_db_size_bytes) / (1024 * 1024), 1);
        $vaultMb = number_format(((int) $intel['total_vault_storage_bytes']) / (1024 * 1024), 2);
        $secThreats = (int) ($website->security_threats_count ?? 0);
    @endphp
    <table class="kpi-table">
        <tr>
            <td class="kpi-card">
                <div class="kpi-label">Protection Score</div>
                <div class="kpi-value" style="color:{{ $intel['protection_score'] >= 80 ? '#16a34a' : '#d97706' }};">
                    {{ $intel['protection_score'] }} / 100
                </div>
                <div class="kpi-sub">Active Policies: {{ $intel['active_policies_count'] }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-label">Live Domain &amp; Speed</div>
                <div class="kpi-value">
                    {{ $website->uptime_status === 'online' ? 'Online' : 'Unreachable' }}
                </div>
                <div class="kpi-sub">
                    HTTP {{ $website->last_http_status_code ?: 0 }} • {{ $website->last_response_time_ms ?: 0 }} ms
                </div>
            </td>
            <td class="kpi-card">
                <div class="kpi-label">SSL Certificate Guard</div>
                <div class="kpi-value" style="color:{{ ($ssl['days_remaining'] ?? 0) > 14 ? '#16a34a' : '#dc2626' }};">
                    {{ $ssl['days_remaining'] !== null ? $ssl['days_remaining'].' Days Left' : 'No SSL' }}
                </div>
                <div class="kpi-sub">Issuer: {{ $ssl['issuer'] ?? 'N/A' }}</div>
            </td>
            <td class="kpi-card">
                <div class="kpi-label">Vault Backups ({{ $periodDays }}d)</div>
                <div class="kpi-value">{{ $periodCompletedCount }} Completed</div>
                <div class="kpi-sub">Total Vault: {{ $intel['total_completed_backups'] }} ({{ $vaultMb }} MB)</div>
            </td>
        </tr>
    </table>

    <!-- Section 1: Domain, DNS, SSL & Connected Server Health -->
    <div class="section-box">
        <div class="section-title">1. Live Domain, DNS, SSL Expiry &amp; Server Infrastructure Health</div>
        <table class="data-table">
            <tbody>
                <tr>
                    <td style="width:28%;font-weight:700;color:#475569;">Domain &amp; Primary IP (A Record)</td>
                    <td style="width:22%;">{{ $intel['clean_host'] }} ({{ $intel['primary_ip'] ?: 'Unresolved' }})</td>
                    <td style="width:25%;font-weight:700;color:#475569;">SSL Expiration Date</td>
                    <td style="width:25%;">{{ $ssl['expires_at'] ?: 'N/A' }} ({{ $ssl['status_label'] }})</td>
                </tr>
                <tr>
                    <td style="font-weight:700;color:#475569;">Source Footprint (Files + DB)</td>
                    <td>{{ $filesMb }} MB Files + {{ $dbMb }} MB DB</td>
                    <td style="font-weight:700;color:#475569;">Server Live Status</td>
                    <td>{{ strtoupper((string) ($website->server_live_status ?: 'OK')) }}</td>
                </tr>
                <tr>
                    <td style="font-weight:700;color:#475569;">Owner Alert Email &amp; Failover</td>
                    <td colspan="3">
                        {{ $intel['alert_email'] }} • Smart Auto-Fallback ({{ $intel['preferred_email_mode'] === 'custom_smtp' ? 'Custom SMTP -> System Wallet' : 'System Wallet -> Custom SMTP' }}) Active
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Section 2: 100% Read-Only Malware, Web-Shell & Security Shield Report -->
    @php
        $secReport = $website->security_scan_report ?? [];
        $secFindings = $secReport['findings'] ?? [];
    @endphp
    <div class="section-box">
        <div class="section-title">
            2. Read-Only Malware, Web-Shell &amp; Security Shield Audit
            <span style="float:right;font-size:11px;font-weight:700;color:{{ $secThreats > 0 ? '#dc2626' : '#16a34a' }};">
                {{ $secThreats > 0 ? $secThreats.' Critical/High Risk(s) Reported' : 'CLEAN (0 Malware / 0 Web-Shells)' }}
            </span>
        </div>
        <div style="font-size:10.5px;color:#64748b;margin-bottom:8px;">
            <strong>100% Read-Only Non-Destructive Guarantee:</strong> All security inspections are strictly read-only. No file or database record on the live server is ever modified or deleted.
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:14%;">Severity</th>
                    <th style="width:28%;">Detected Issue</th>
                    <th style="width:32%;">Exact Server File / Location</th>
                    <th style="width:26%;">Evidence / Advice</th>
                </tr>
            </thead>
            <tbody>
                @forelse($secFindings as $item)
                    <tr>
                        <td style="font-weight:700;color:{{ in_array($item['severity'] ?? '', ['CRITICAL', 'HIGH']) ? '#dc2626' : '#d97706' }};">
                            {{ $item['severity'] ?? 'LOW' }}
                        </td>
                        <td>
                            <strong>{{ $item['label'] ?? '' }}</strong><br>
                            <span style="color:#64748b;font-size:10px;">{{ $item['category'] ?? '' }}</span>
                        </td>
                        <td style="font-family:monospace;font-size:10.5px;word-break:break-all;">
                            {{ $item['location'] ?? '' }}
                            @if(!empty($item['line'])) (Line {{ $item['line'] }}) @endif
                        </td>
                        <td style="font-size:10.5px;">{{ $item['recommendation'] ?? '' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" style="text-align:center;color:#16a34a;font-weight:700;padding:12px;">
                            ✓ No web-shells, obfuscated PHP malware, or publicly exposed .env / .git files were detected.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- Section 3: Verified Backup Runs History -->
    <div class="section-box">
        <div class="section-title">3. Verified Cloud Backup History (Recent {{ $recentRuns->count() }} Snapshots)</div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Run ID</th>
                    <th>Backup Policy</th>
                    <th>Type &amp; Scope</th>
                    <th>Archive Size</th>
                    <th>Status</th>
                    <th>Completed At (UTC)</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recentRuns as $run)
                    @php
                        $statusStr = strtoupper((string) ($run->status?->value ?? $run->status));
                    @endphp
                    <tr>
                        <td style="font-family:monospace;">#{{ $run->id }}</td>
                        <td>{{ $run->backupPolicy?->name ?? 'Manual Snapshot' }}</td>
                        <td>{{ strtoupper((string) $run->backup_type) }} • {{ $run->scope?->value ?? $run->scope }}</td>
                        <td>{{ $run->formattedSize() }}</td>
                        <td style="font-weight:700;color:{{ $statusStr === 'COMPLETED' ? '#16a34a' : '#dc2626' }};">{{ $statusStr }}</td>
                        <td>{{ ($run->completed_at ?: $run->created_at)?->format('d M Y, H:i') }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align:center;color:#64748b;padding:12px;">No backup runs recorded for this website yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <!-- White-Label Agency Footer -->
    <div class="footer-box">
        <div><strong>{{ $agencyFooter }}</strong></div>
        <div style="margin-top:4px;">
            Agency Contact: {{ $agencySupportEmail }} • Report Ref: {{ $reportReference }}
        </div>
    </div>
</div>

</body>
</html>

