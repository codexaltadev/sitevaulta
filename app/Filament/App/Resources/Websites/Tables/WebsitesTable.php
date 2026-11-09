<?php

namespace App\Filament\App\Resources\Websites\Tables;

use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use App\Models\ServerConnection;
use App\Models\Website;
use App\Services\Verification\DomainVerificationService;
use App\Services\Verification\WebsiteUptimeMonitorService;
use Filament\Actions\Action;
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

                TextColumn::make('verification_status')
                    ->label('Ownership Status')
                    ->badge()
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

                TextColumn::make('verified_at')
                    ->label('Verified At')
                    ->dateTime()
                    ->placeholder('Not verified yet'),
            ])
            ->recordActions([
                Action::make('viewSiteIntelligence')
                    ->label('View')
                    ->icon(Heroicon::OutlinedEye)
                    ->color('info')
                    ->modalWidth('5xl')
                    ->modalHeading(fn (Website $record): string => "🌐 Site & Server Intelligence — {$record->name} ({$record->domain})")
                    ->modalDescription('Real-time Domain DNS, SSL Expiry Guard, Connected Server & DB Health, Smart Email Alert Routing, and Backup Protection Footprint.')
                    ->modalSubmitActionLabel('🔄 Re-Scan Live Domain & Server Now')
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        Placeholder::make('site_intelligence_dashboard')
                            ->hiddenLabel()
                            ->content(fn (Website $record): HtmlString => self::renderSiteIntelligenceHtml($record)),
                    ])
                    ->action(function (Website $record): void {
                        $result = WebsiteUptimeMonitorService::checkWebsiteLiveStatus($record);

                        if ($result['is_online']) {
                            Notification::make()
                                ->title('🟢 Live Scan Complete: Domain & Server are Online!')
                                ->body($result['message'])
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('🔴 Live Scan Complete: Domain or Server is Unreachable!')
                                ->body($result['message'].($result['email_notified'] ? ' (Owner alert email dispatched)' : ''))
                                ->danger()
                                ->send();
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
                                    .'<div><strong style="color:var(--sv-primary);">File Path:</strong> <code>%s</code></div>'
                                    .'<div><strong style="color:var(--sv-primary);">Meta Tag:</strong> <code>&lt;meta name="%s" content="%s"&gt;</code></div>'
                                    .'</div>',
                                    e($challenge->token),
                                    e($challenge->dns_host),
                                    e($challenge->file_path),
                                    e($challenge->meta_tag_name),
                                    e($challenge->token)
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
            ]);
    }

    protected static function renderSiteIntelligenceHtml(Website $record): HtmlString
    {
        // Auto-run fresh live check if never checked before or if last check is older than 2 minutes
        if (! $record->last_uptime_checked_at || $record->last_uptime_checked_at->diffInMinutes(now()) >= 2) {
            WebsiteUptimeMonitorService::checkWebsiteLiveStatus($record);
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

    <!-- Card 3: Recent 5 Backup Runs -->
    <div style="padding:16px;border-radius:12px;background:rgba(30,41,59,0.75);border:1px solid rgba(148,163,184,0.2);">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
            <h4 style="margin:0;font-size:14px;font-weight:700;color:#34d399;">🛡️ 3. Recent Backup Runs & Recovery Readiness (Active Policies: {$intel['active_policies_count']})</h4>
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
