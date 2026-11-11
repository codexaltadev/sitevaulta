<?php

namespace App\Filament\App\Resources\Websites\Tables;

use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use App\Models\ServerConnection;
use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\WebsiteSecurityScannerService;
use App\Services\Verification\DomainVerificationService;
use App\Services\Verification\OwnershipChallengeChecker;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class WebsitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('verifiedDomain'))
            ->columns([
                TextColumn::make('name')
                    ->label('Website')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('environment')
                    ->badge(),

                // Read from the domain's ownership record (verified_domains), the single source of truth.
                TextColumn::make('ownership')
                    ->label('Ownership Status')
                    ->badge()
                    ->state(fn (Website $record): WebsiteVerificationStatus => $record->verificationStatus())
                    ->formatStateUsing(fn (WebsiteVerificationStatus $state): string => $state === WebsiteVerificationStatus::Verified ? 'Verified Owner' : $state->label())
                    ->icon(fn (WebsiteVerificationStatus $state): Heroicon => match ($state) {
                        WebsiteVerificationStatus::Verified => Heroicon::OutlinedShieldCheck,
                        WebsiteVerificationStatus::Failed => Heroicon::OutlinedShieldExclamation,
                        default => Heroicon::OutlinedClock,
                    })
                    ->tooltip(fn (Website $record, WebsiteVerificationStatus $state): string => match ($state) {
                        WebsiteVerificationStatus::Verified => 'Ownership proven via '.$record->verifiedDomain?->methodLabel().' — this domain also counts as verified for uptime monitoring (port checks allowed).',
                        WebsiteVerificationStatus::Failed => collect($record->verifiedDomain?->errorLines() ?? [])->map(fn (string $line): string => "✗ {$line}")->implode("\n"),
                        default => 'Verify ownership to unlock backups and port checks for this domain.',
                    })
                    ->color(fn (WebsiteVerificationStatus $state): string => $state->color()),

                TextColumn::make('uptime_status')
                    ->label('Live Domain & Server Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Website $record): string => match ($state) {
                        'online' => '🟢 Online'.($record->last_http_status_code ? " ({$record->last_http_status_code} • {$record->last_response_time_ms}ms)" : ''),
                        'down' => '🔴 Down / Unreachable',
                        default => '⚪ Not Checked Yet',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'online' => 'success',
                        'down' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Website $record): string => $record->uptime_status === 'down'
                        ? Str::limit((string) ($record->last_down_reason ?: 'Domain or server unreachable'), 65)
                        : ($record->last_uptime_checked_at
                            ? 'Server: '.strtoupper((string) ($record->server_live_status ?: 'OK')).' • Checked '.$record->last_uptime_checked_at->diffForHumans()
                            : 'Click "Check Live Status" or "View"')),

                TextColumn::make('security_scan_status')
                    ->label('Security & Malware Shield')
                    ->badge()
                    ->formatStateUsing(fn (?string $state, Website $record): string => match ($state) {
                        'clean' => '🛡️ Clean (0 Threats)',
                        'warning' => '⚠️ Header Warning (0 Malware)',
                        'infected' => '🚨 '.((int) $record->security_threats_count).' Threat(s) Found',
                        default => '⚪ Not Scanned',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'clean' => 'success',
                        'warning' => 'warning',
                        'infected' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (Website $record): string => $record->last_security_scan_at
                        ? 'Read-Only Scan • '.$record->last_security_scan_at->diffForHumans()
                        : 'Click "View" or "Scan Malware"'),
            ])
            ->recordActions([
                Action::make('viewSiteIntelligence')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('info')
                    ->modalWidth('5xl')
                    ->modalHeading(fn (Website $record): string => "🌐 Site & Server Intelligence — {$record->name} ({$record->domain})")
                    ->modalDescription('Real-time Domain DNS, SSL Expiry Guard, Connected Server & DB Health, 100% Read-Only Malware & Web-Shell Scanner, and Backup Footprint.')
                    ->modalSubmitActionLabel('🔄 Re-Scan Live Domain, Server & Security Shield Now')
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Placeholder::make('site_intelligence_dashboard')
                            ->hiddenLabel()
                            ->content(fn (Website $record): HtmlString => self::renderSiteIntelligenceHtml($record)),
                    ])
                    ->action(function (Website $record): void {
                        $result = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($record);
                        $secResult = WebsiteSecurityScannerService::scanWebsiteAndServer($record);

                        $secText = $secResult['critical_count'] > 0
                            ? " | 🚨 {$secResult['critical_count']} Security Threat(s) Reported (Read-Only)"
                            : ' | 🛡️ 0 Malware/Web-Shells';

                        if ($result['is_online']) {
                            Notification::make()
                                ->title('🟢 Live Scan Complete: Domain & Server are Online!')
                                ->body($result['message'].$secText)
                                ->success()
                                ->send();

                            if ($record->workspace) {
                                WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace(
                                    workspace: $record->workspace,
                                    title: "🟢 Live Scan: {$record->name} ({$record->domain}) is Online",
                                    body: $result['message'].$secText,
                                    status: 'success'
                                );
                            }
                        } else {
                            Notification::make()
                                ->title('🔴 Live Scan Complete: Domain or Server is Unreachable!')
                                ->body($result['message'].$secText.($result['email_notified'] ? ' (Owner alert email dispatched)' : ''))
                                ->danger()
                                ->send();
                        }
                    }),

                ActionGroup::make([
                    Action::make('clientReport')
                        ->label('Client PDF Report')
                        ->icon(Heroicon::OutlinedDocumentChartBar)
                        ->color('primary')
                        ->url(fn (Website $record): string => route('websites.client-report.view', [
                            'workspaceSlug' => $record->workspace?->slug ?? 'demo-agency',
                            'websiteUuid' => $record->uuid,
                        ]))
                        ->openUrlInNewTab(),

                    Action::make('scanMalwareReadOnly')
                        ->label('Scan Malware (Read-Only)')
                        ->icon(Heroicon::OutlinedShieldCheck)
                        ->color('warning')
                        ->action(function (Website $record): void {
                            $secResult = WebsiteSecurityScannerService::scanWebsiteAndServer($record);

                            if ($secResult['critical_count'] > 0) {
                                Notification::make()
                                    ->title("🚨 {$secResult['critical_count']} Suspicious File(s) / Exposure(s) Detected!")
                                    ->body('100% Read-Only Report generated. Click "View" to see exact file locations on your server. No files were deleted or modified.')
                                    ->danger()
                                    ->persistent()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('🛡️ Read-Only Security Scan Clean (0 Malware / 0 Web-Shells)')
                                    ->body('No web-shells, obfuscated malware, or exposed .env/.git files were detected.')
                                    ->success()
                                    ->send();

                                if ($record->workspace) {
                                    WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace(
                                        workspace: $record->workspace,
                                        title: "🛡️ Security Scan Clean: {$record->name} ({$record->domain})",
                                        body: '100% Read-Only Scan completed: 0 Malware, 0 Web-Shells, and 0 Exposed .env/.git files detected.',
                                        status: 'success'
                                    );
                                }
                            }
                        }),

                    Action::make('checkLiveUptime')
                        ->label('Check Live Status')
                        ->icon(Heroicon::OutlinedSignal)
                        ->color('success')
                        ->action(function (Website $record): void {
                            $result = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($record);

                            if ($result['is_online']) {
                                Notification::make()
                                    ->title('🟢 Domain & Server are Live!')
                                    ->body($result['message'])
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('🔴 Domain or Server is Unreachable!')
                                    ->body($result['message'].($result['email_notified'] ? ' (Alert email sent to owner)' : ''))
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Action::make('verifyDomain')
                        ->label('Verify Ownership')
                        ->icon(Heroicon::OutlinedCheckBadge)
                        ->color('success')
                        ->schema([
                            Placeholder::make('challenge_instructions')
                                ->label('Verification Challenge Token')
                                ->content(function (Website $record): HtmlString {
                                    $challenge = DomainVerificationService::ensureChallengeExists($record);

                                    return new HtmlString(sprintf(
                                        '<div style="padding:14px;border-radius:8px;background:var(--sv-bg);color:var(--sv-text);border:1px solid var(--sv-border);line-height:1.7;">'
                                        .'<div><strong style="color:var(--sv-primary);">Token:</strong> <code>%s</code></div>'
                                        .'<div><strong style="color:var(--sv-primary);">DNS TXT Host:</strong> <code>%s</code></div>'
                                        .'<div><strong style="color:var(--sv-primary);">File Path:</strong> <code>%s</code> or <code>%s</code> (web root)</div>'
                                        .'<div><strong style="color:var(--sv-primary);">Meta Tag:</strong> <code>%s</code></div>'
                                        .'</div>',
                                        e($challenge->token),
                                        e($challenge->dnsRecordName()),
                                        e(VerifiedDomain::FILE_PATH),
                                        e(OwnershipChallengeChecker::ROOT_FILE_PATH),
                                        e($challenge->metaTag()),
                                    ));
                                }),

                            Select::make('method')
                                ->label('Select Verification Method to Check')
                                ->options(VerificationMethod::options())
                                ->default(VerificationMethod::DnsTxt->value)
                                ->required(),
                        ])
                        ->action(function (Website $record, array $data): void {
                            $method = VerificationMethod::from($data['method']);
                            $result = DomainVerificationService::verifyWebsite($record, $method);

                            if ($result->verified) {
                                Notification::make()
                                    ->title('Domain Ownership Verified!')
                                    ->body($result->message)
                                    ->success()
                                    ->send();
                            } else {
                                Notification::make()
                                    ->title('Verification Check Failed')
                                    ->body($result->message)
                                    ->danger()
                                    ->send();
                            }
                        }),

                    Action::make('generateWebhookToken')
                        ->label('CI/CD Pre-Deploy Token')
                        ->icon(Heroicon::OutlinedKey)
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading('Generate CI/CD Pre-Deploy Webhook Token')
                        ->modalDescription('Generates a new bearer token so you can trigger an instant pre-deployment backup from GitHub Actions or GitLab CI.')
                        ->action(function (Website $record): void {
                            $plainToken = 'sv_deploy_'.Str::random(36);
                            $record->update([
                                'webhook_secret_hash' => Hash::make($plainToken),
                            ]);

                            $endpoint = url("/api/v1/websites/{$record->uuid}/trigger-backup");

                            Notification::make()
                                ->title('Pre-Deploy Webhook Token Generated (Copy Now)')
                                ->body("Endpoint: POST {$endpoint} | Bearer Token: {$plainToken}")
                                ->persistent()
                                ->success()
                                ->send();
                        }),

                    EditAction::make(),
                    DeleteAction::make(),
                ])
                    ->label('Tools & Actions')
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->color('gray')
                    ->button(),
            ]);
    }

    protected static function renderSiteIntelligenceHtml(Website $record): HtmlString
    {
        // Auto-run fresh live check if never checked before or if last check is older than 2 minutes
        if (! $record->last_uptime_checked_at || $record->last_uptime_checked_at->diffInMinutes(now()) >= 2) {
            WebsiteUptimeMonitorService::checkWebsiteLiveStatus($record);
            $record->refresh();
        }

        // Auto-run Read-Only Security Scan if never scanned before
        if (! $record->last_security_scan_at) {
            WebsiteSecurityScannerService::scanWebsiteAndServer($record, sendAlertIfThreatsFound: false);
            $record->refresh();
        }

        $intel = WebsiteUptimeMonitorService::inspectSiteAndServerIntelligence($record);
        $isOnline = $record->uptime_status === 'online';

        $statusColor = $isOnline ? '#10b981' : '#ef4444';
        $statusBg = $isOnline ? 'rgba(16, 185, 129, 0.12)' : 'rgba(239, 68, 68, 0.12)';
        $statusBadge = $isOnline
            ? "🟢 ONLINE & REACHABLE (HTTP {$record->last_http_status_code} • {$record->last_response_time_ms}ms)"
            : '🔴 DOWN / UNREACHABLE';

        $score = (int) $intel['protection_score'];
        $scoreColor = match (true) {
            $score >= 80 => '#10b981',
            $score >= 50 => '#f59e0b',
            default => '#ef4444',
        };

        $filesMb = number_format(((int) $record->known_files_size_bytes) / (1024 * 1024), 1);
        $dbMb = number_format(((int) $record->known_db_size_bytes) / (1024 * 1024), 1);
        $totalSourceMb = number_format((((int) $record->known_files_size_bytes) + ((int) $record->known_db_size_bytes)) / (1024 * 1024), 1);
        $vaultGb = number_format(((int) $intel['total_vault_storage_bytes']) / (1024 * 1024), 2);

        $dnsDisplay = $intel['primary_ip']
            ? '<span style="color:#10b981;font-weight:700;">'.e($intel['primary_ip']).'</span>'
            : '<span style="color:#ef4444;font-weight:700;">❌ Unresolved (NXDOMAIN / Fake or Offline Domain)</span>';

        $ssl = $intel['ssl'];
        $sslExpiryText = $ssl['expires_at']
            ? e($ssl['expires_at'])." ({$ssl['days_remaining']} days left)"
            : 'Not Available';

        $checkedAgo = $record->last_uptime_checked_at
            ? $record->last_uptime_checked_at->diffForHumans()
            : 'Just now';

        $clientReportUrl = route('websites.client-report.view', [
            'workspaceSlug' => $record->workspace?->slug ?? 'demo-agency',
            'websiteUuid' => $record->uuid,
        ]);

        $downReasonBanner = (! $isOnline && $record->last_down_reason)
            ? '<div style="margin-top:10px;padding:10px 14px;border-radius:8px;background:rgba(239,68,68,0.15);border:1px solid rgba(239,68,68,0.4);color:#fca5a5;font-size:13px;">'
              .'<strong>🚨 Live Diagnostic Reason:</strong> '.e($record->last_down_reason)
              .'</div>'
            : '';

        // Connected Servers HTML
        $serversHtml = '';
        if ($record->serverConnections->isEmpty()) {
            $serversHtml = '<div style="padding:12px;color:#94a3b8;font-size:13px;">No server connection attached to this website yet.</div>';
        } else {
            foreach ($record->serverConnections as $conn) {
                /** @var ServerConnection $conn */
                $dbCreds = $conn->db_credentials ?? [];
                $endpoint = WebsiteUptimeMonitorService::resolveServerEndpoint($conn, $record);
                $host = $endpoint['host'] ?: 'N/A';
                $displayEndpoint = $endpoint['display'];
                $rootPath = $endpoint['root_path'];
                $isFakeHost = WebsiteUptimeMonitorService::isReservedOrFakeHost((string) $host);
                $srvOnline = ($record->server_live_status === 'online') && ! $isFakeHost;

                $srvBadge = $srvOnline
                    ? '<span style="padding:2px 8px;border-radius:999px;background:rgba(16,185,129,0.2);color:#34d399;font-size:11px;font-weight:700;">🟢 Reachable</span>'
                    : '<span style="padding:2px 8px;border-radius:999px;background:rgba(239,68,68,0.2);color:#f87171;font-size:11px;font-weight:700;">🔴 Unreachable'.($isFakeHost ? ' (Test/Fake IP)' : '').'</span>';

                $dbDriverLabel = strtoupper((string) ($conn->db_driver?->value ?? $conn->db_driver ?? 'None'));
                $dbName = $dbCreds['database'] ?? 'N/A';
                $tunnelBadge = $conn->db_use_ssh_tunnel ? ' (via SSH Tunnel)' : '';

                $serversHtml .= sprintf(
                    '<div style="padding:12px;border-radius:8px;background:rgba(15,23,42,0.55);border:1px solid rgba(148,163,184,0.18);margin-bottom:8px;">'
                    .'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
                    .'<strong style="color:#f8fafc;font-size:13px;">🖥️ %s (%s)</strong>%s'
                    .'</div>'
                    .'<div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px;font-size:12px;color:#cbd5e1;">'
                    .'<div><span style="color:#94a3b8;">Endpoint:</span> <code>%s</code></div>'
                    .'<div><span style="color:#94a3b8;">Root Path:</span> <code>%s</code></div>'
                    .'<div><span style="color:#94a3b8;">Database Engine:</span> <strong>%s</strong>%s</div>'
                    .'<div><span style="color:#94a3b8;">Database Name:</span> <code>%s</code></div>'
                    .'</div>'
                    .'</div>',
                    e($conn->name),
                    e($endpoint['protocol']),
                    $srvBadge,
                    e($displayEndpoint),
                    e((string) $rootPath),
                    e($dbDriverLabel),
                    e($tunnelBadge),
                    e((string) $dbName)
                );
            }
        }

        // Email Routing & Fallback HTML
        $preferredModeLabel = $intel['preferred_email_mode'] === 'custom_smtp'
            ? 'Own Custom SMTP (Free — Unlimited)'
            : 'System Email (Digital Wallet — 1 Credit/Email)';

        $fallbackBadge = $intel['fallback_ready']
            ? '<span style="color:#10b981;font-weight:700;">🛡️ Active & Ready (Auto-switches if primary is unavailable)</span>'
            : '<span style="color:#f59e0b;font-weight:700;">⚠️ Top up Wallet or configure Custom SMTP</span>';

        // Read-Only Malware, Web-Shell & Security Shield Report HTML
        $secReport = $record->security_scan_report ?? [];
        $secFindings = $secReport['findings'] ?? [];
        $secScannedAgo = $record->last_security_scan_at ? $record->last_security_scan_at->diffForHumans() : 'Just now';
        $secCriticalCount = (int) ($record->security_threats_count ?? 0);

        $secBadgeHtml = $secCriticalCount > 0
            ? "<span style='padding:3px 10px;border-radius:999px;background:rgba(239,68,68,0.2);color:#f87171;font-size:12px;font-weight:700;'>🚨 {$secCriticalCount} Critical/High Threat(s) Reported</span>"
            : "<span style='padding:3px 10px;border-radius:999px;background:rgba(16,185,129,0.2);color:#34d399;font-size:12px;font-weight:700;'>🛡️ Clean — 0 Malware / 0 Web-Shells Found</span>";

        $secRowsHtml = '';
        if (empty($secFindings)) {
            $secRowsHtml = '<tr><td colspan="4" style="padding:12px;text-align:center;color:#34d399;font-size:12.5px;">✅ No suspicious web-shells, obfuscated PHP malware, or publicly exposed .env / .git files were found.</td></tr>';
        } else {
            foreach ($secFindings as $item) {
                $sev = (string) ($item['severity'] ?? 'LOW');
                $sevColor = match ($sev) {
                    'CRITICAL', 'HIGH' => '#ef4444',
                    'MEDIUM' => '#f59e0b',
                    default => '#38bdf8',
                };
                $lineTag = ! empty($item['line']) ? ' <span style="color:#94a3b8;">(Line '.(int) $item['line'].')</span>' : '';

                $secRowsHtml .= sprintf(
                    '<tr style="border-top:1px solid rgba(148,163,184,0.15);">'
                    .'<td style="padding:8px 10px;font-size:11px;font-weight:800;color:%s;">%s</td>'
                    .'<td style="padding:8px 10px;font-size:12px;color:#f8fafc;"><strong>%s</strong><div style="font-size:11px;color:#94a3b8;">%s</div></td>'
                    .'<td style="padding:8px 10px;font-size:12px;color:#38bdf8;font-family:monospace;word-break:break-all;"><code>%s</code>%s</td>'
                    .'<td style="padding:8px 10px;font-size:11.5px;color:#cbd5e1;"><div><code>%s</code></div><div style="color:#94a3b8;margin-top:2px;">%s</div></td>'
                    .'</tr>',
                    $sevColor,
                    e($sev),
                    e((string) ($item['label'] ?? '')),
                    e((string) ($item['category'] ?? '')),
                    e((string) ($item['location'] ?? '')),
                    $lineTag,
                    e((string) ($item['evidence'] ?? '')),
                    e((string) ($item['recommendation'] ?? ''))
                );
            }
        }

        // Recent 5 Backup Runs HTML
        $runsRowsHtml = '';
        if ($intel['recent_runs']->isEmpty()) {
            $runsRowsHtml = '<tr><td colspan="5" style="padding:12px;text-align:center;color:#94a3b8;">No backup runs recorded for this website yet.</td></tr>';
        } else {
            foreach ($intel['recent_runs'] as $run) {
                $statusVal = $run->status?->value ?? (string) $run->status;
                $runColor = $statusVal === 'completed' ? '#10b981' : ($statusVal === 'failed' ? '#ef4444' : '#f59e0b');
                $sizeMb = number_format(((int) $run->archive_size_bytes) / (1024 * 1024), 2).' MB';
                $when = $run->created_at ? $run->created_at->diffForHumans() : '-';

                $runsRowsHtml .= sprintf(
                    '<tr style="border-top:1px solid rgba(148,163,184,0.15);">'
                    .'<td style="padding:8px 10px;font-family:monospace;font-size:12px;color:#e2e8f0;">#%d</td>'
                    .'<td style="padding:8px 10px;font-size:12px;color:#e2e8f0;">%s</td>'
                    .'<td style="padding:8px 10px;font-size:12px;"><span style="color:%s;font-weight:700;">%s</span></td>'
                    .'<td style="padding:8px 10px;font-size:12px;color:#cbd5e1;">%s</td>'
                    .'<td style="padding:8px 10px;font-size:12px;color:#94a3b8;">%s</td>'
                    .'</tr>',
                    $run->id,
                    e($run->backupPolicy?->name ?? 'Manual Run'),
                    $runColor,
                    e(strtoupper($statusVal)),
                    e($sizeMb),
                    e($when)
                );
            }
        }

        $html = <<<HTML
<div style="display:flex;flex-direction:column;gap:16px;color:#e2e8f0;font-family:Inter,system-ui,sans-serif;">

    <!-- Top Executive Banner + 4 KPI Cards -->
    <div style="padding:16px;border-radius:12px;background:{$statusBg};border:1px solid {$statusColor};">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:12px;">
            <div>
                <div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;color:#cbd5e1;font-weight:600;">Real-Time Live Telemetry • Checked {$checkedAgo}</div>
                <div style="font-size:18px;font-weight:800;color:{$statusColor};margin-top:2px;">{$statusBadge}</div>
            </div>
            <div style="display:flex;gap:14px;align-items:center;">
                <div style="text-align:right;">
                    <div style="font-size:11px;color:#cbd5e1;">Protection Score</div>
                    <div style="font-size:20px;font-weight:800;color:{$scoreColor};">{$score} / 100</div>
                </div>
                <div style="text-align:right;border-left:1px solid rgba(148,163,184,0.3);padding-left:14px;">
                    <div style="font-size:11px;color:#cbd5e1;">Vault Backups</div>
                    <div style="font-size:18px;font-weight:800;color:#f8fafc;">{$intel['total_completed_backups']} ({$vaultGb} MB)</div>
                </div>
                <div style="border-left:1px solid rgba(148,163,184,0.3);padding-left:14px;">
                    <a href="{$clientReportUrl}" target="_blank" style="display:inline-block;padding:8px 14px;border-radius:8px;background:#0d9488;color:#ffffff;font-weight:700;font-size:12px;text-decoration:none;">
                        📄 Client PDF Report
                    </a>
                </div>
            </div>
        </div>
        {$downReasonBanner}
    </div>

    <!-- Grid: Domain/SSL Guard + Connected Server Telemetry -->
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;">

        <!-- Card 1: Domain, DNS & SSL Expiry Guard -->
        <div style="padding:16px;border-radius:12px;background:rgba(30,41,59,0.75);border:1px solid rgba(148,163,184,0.2);">
            <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:700;color:#38bdf8;">🔒 1. Live Domain, DNS & SSL Expiry Guard</h4>
            <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;">
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(148,163,184,0.15);padding-bottom:6px;">
                    <span style="color:#94a3b8;">Hostname:</span>
                    <code>{$intel['clean_host']}</code>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(148,163,184,0.15);padding-bottom:6px;">
                    <span style="color:#94a3b8;">Resolved DNS IP (A Record):</span>
                    <span>{$dnsDisplay}</span>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(148,163,184,0.15);padding-bottom:6px;">
                    <span style="color:#94a3b8;">SSL Certificate Status:</span>
                    <span style="color:{$ssl['badge_color']};font-weight:700;">{$ssl['status_label']}</span>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(148,163,184,0.15);padding-bottom:6px;">
                    <span style="color:#94a3b8;">SSL Issuer Authority:</span>
                    <strong style="color:#f8fafc;">{$ssl['issuer']}</strong>
                </div>
                <div style="display:flex;justify-content:space-between;border-bottom:1px dashed rgba(148,163,184,0.15);padding-bottom:6px;">
                    <span style="color:#94a3b8;">SSL Expiration Date:</span>
                    <span style="color:#e2e8f0;">{$sslExpiryText}</span>
                </div>
                <div style="display:flex;justify-content:space-between;">
                    <span style="color:#94a3b8;">Source Footprint (Files + DB):</span>
                    <strong style="color:#38bdf8;">{$filesMb} MB Files + {$dbMb} MB DB = {$totalSourceMb} MB</strong>
                </div>
            </div>
        </div>

        <!-- Card 2: Connected Server & Database Telemetry -->
        <div style="padding:16px;border-radius:12px;background:rgba(30,41,59,0.75);border:1px solid rgba(148,163,184,0.2);">
            <h4 style="margin:0 0 12px 0;font-size:14px;font-weight:700;color:#a78bfa;">🖥️ 2. Connected Server & Database Health</h4>
            {$serversHtml}
            <div style="margin-top:10px;padding-top:10px;border-top:1px dashed rgba(148,163,184,0.2);font-size:12px;color:#cbd5e1;">
                <div style="margin-bottom:4px;"><strong style="color:#34d399;">📧 Owner Alert Email:</strong> <code>{$intel['alert_email']}</code></div>
                <div style="margin-bottom:4px;"><strong style="color:#38bdf8;">🚀 Selected Email Channel:</strong> {$preferredModeLabel}</div>
                <div><strong>🔄 Smart Auto-Fallback:</strong> {$fallbackBadge}</div>
            </div>
        </div>
    </div>

    <!-- Card 3: Read-Only Malware, Web-Shell & Security Shield Report -->
    <div style="padding:16px;border-radius:12px;background:rgba(30,41,59,0.75);border:1px solid rgba(148,163,184,0.2);">
        <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px;margin-bottom:10px;">
            <div>
                <h4 style="margin:0;font-size:14px;font-weight:700;color:#f59e0b;">🛡️ 3. Malware, Web-Shell &amp; Security Shield Report (Scanned {$secScannedAgo})</h4>
                <div style="font-size:11.5px;color:#94a3b8;margin-top:3px;">
                    🔒 <strong>100% Read-Only Non-Destructive Guarantee:</strong> Reports exact file locations only. SiteVaulta <strong>NEVER</strong> deletes or modifies any file on your live server.
                </div>
            </div>
            <div>{$secBadgeHtml}</div>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;text-align:left;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(148,163,184,0.25);font-size:11px;text-transform:uppercase;color:#94a3b8;">
                        <th style="padding:6px 10px;width:90px;">Severity</th>
                        <th style="padding:6px 10px;width:230px;">Detected Issue / Category</th>
                        <th style="padding:6px 10px;">Exact File Location / Server Path</th>
                        <th style="padding:6px 10px;">Matched Evidence &amp; Manual Advice</th>
                    </tr>
                </thead>
                <tbody>
                    {$secRowsHtml}
                </tbody>
            </table>
        </div>
    </div>

    <!-- Card 4: Recent 5 Backup Runs -->
    <div style="padding:16px;border-radius:12px;background:rgba(30,41,59,0.75);border:1px solid rgba(148,163,184,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h4 style="margin:0;font-size:14px;font-weight:700;color:#34d399;">📦 4. Recent Backup Runs &amp; Recovery Readiness (Active Policies: {$intel['active_policies_count']})</h4>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;text-align:left;">
                <thead>
                    <tr style="border-bottom:1px solid rgba(148,163,184,0.25);font-size:11px;text-transform:uppercase;color:#94a3b8;">
                        <th style="padding:6px 10px;">Run ID</th>
                        <th style="padding:6px 10px;">Policy</th>
                        <th style="padding:6px 10px;">Status</th>
                        <th style="padding:6px 10px;">Archive Size</th>
                        <th style="padding:6px 10px;">Time</th>
                    </tr>
                </thead>
                <tbody>
                    {$runsRowsHtml}
                </tbody>
            </table>
        </div>
    </div>

</div>
HTML;

        return new HtmlString($html);
    }
}
