<?php

namespace App\Filament\App\Resources\BackupPolicies\Schemas;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Enums\DownloadMode;
use App\Enums\ScheduleFrequency;
use App\Models\BackupTemplate;
use App\Models\ServerConnection;
use App\Models\StorageConnection;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Forecast\StorageForecastService;
use App\Services\Settings\WorkspaceSettingsService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class BackupPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        /** @var Workspace|null $workspace */
        $workspace = Filament::getTenant();
        $defaultRetention = $workspace ? (int) WorkspaceSettingsService::get($workspace, 'backup_defaults', 'default_retention_days', 14) : 14;
        $defaultExcludes = $workspace ? (array) WorkspaceSettingsService::get($workspace, 'backup_defaults', 'default_exclude_paths', ['.git', 'node_modules', 'vendor']) : ['.git', 'node_modules', 'vendor'];
        $defaultEncryption = $workspace ? (bool) WorkspaceSettingsService::get($workspace, 'backup_defaults', 'default_enable_encryption', true) : true;

        return $schema
            ->components([
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        // LEFT COLUMN (7/12): Policy Configuration
                        Grid::make(1)
                            ->columnSpan(7)
                            ->schema([
                                Section::make('Target Website, Server & Storage Destination')
                                    ->description('Bind a verified website, server connector, and customer-owned cloud/VPS storage destination.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Policy Name')
                                            ->placeholder('e.g., Production Daily Incremental + DB Backup')
                                            ->required()
                                            ->maxLength(150)
                                            ->columnSpanFull(),

                                        Select::make('website_id')
                                            ->label('Verified Website')
                                            ->options(function () {
                                                $tenant = Filament::getTenant();

                                                return Website::query()
                                                    ->where('workspace_id', $tenant?->id)
                                                    ->ownershipVerified()
                                                    ->pluck('name', 'id');
                                            })
                                            ->live()
                                            ->afterStateUpdated(function (?int $state, callable $set): void {
                                                if ($state) {
                                                    $conn = ServerConnection::query()->where('website_id', $state)->latest('id')->first();
                                                    if ($conn) {
                                                        $set('server_connection_id', $conn->id);
                                                    }
                                                }
                                            })
                                            ->required()
                                            ->helperText('Only ownership-verified domains can be scheduled.'),

                                        Select::make('server_connection_id')
                                            ->label('Server & DB Connection')
                                            ->helperText('Select the VPS / SSH & Database connector where your website is hosted.')
                                            ->options(function (Get $get) {
                                                $tenant = Filament::getTenant();
                                                $websiteId = $get('website_id');
                                                $query = ServerConnection::query()->where('workspace_id', $tenant?->id);
                                                if ($websiteId) {
                                                    $query->where('website_id', $websiteId);
                                                }

                                                return $query->get()->mapWithKeys(fn (ServerConnection $c) => [
                                                    $c->id => "{$c->name} — {$c->website?->domain} ({$c->file_protocol->label()} / {$c->db_driver->label()})",
                                                ]);
                                            })
                                            ->live()
                                            ->required(),

                                        Select::make('storage_connection_id')
                                            ->label('Customer Storage Destination')
                                            ->helperText('Select your cloud destination (e.g., Google Drive, AWS S3, Cloudflare R2, or SFTP).')
                                            ->options(function () {
                                                $tenant = Filament::getTenant();

                                                return StorageConnection::query()
                                                    ->where('workspace_id', $tenant?->id)
                                                    ->pluck('name', 'id');
                                            })
                                            ->live()
                                            ->required(),

                                        Select::make('template_id')
                                            ->label('Load Preset Policy Template (Optional)')
                                            ->helperText('Optional: Select a preset (e.g., WordPress, Laravel, E-Commerce) to automatically populate all settings.')
                                            ->options(function () {
                                                $tenant = Filament::getTenant();

                                                return BackupTemplate::query()
                                                    ->where('workspace_id', $tenant?->id)
                                                    ->orWhere('is_global_default', true)
                                                    ->pluck('name', 'id');
                                            })
                                            ->live()
                                            ->afterStateUpdated(function (?int $state, callable $set): void {
                                                if (! $state) {
                                                    return;
                                                }
                                                $template = BackupTemplate::find($state);
                                                if ($template) {
                                                    $set('scope', $template->scope->value);
                                                    $set('strategy', $template->strategy->value);
                                                    $set('full_reset_every_n', $template->full_reset_every_n);
                                                    $set('schedule_frequency', $template->schedule_frequency->value);
                                                    $set('interval_minutes', $template->interval_minutes);
                                                    $set('cron_expression', $template->cron_expression);
                                                    $set('retention_days', $template->retention_days);
                                                    $set('exclude_paths', $template->exclude_paths);
                                                    $set('enable_encryption', $template->enable_encryption);
                                                }
                                            }),
                                    ]),

                                Section::make('Schedule, Chain Strategy & Encryption')
                                    ->columns(2)
                                    ->schema([
                                        Select::make('scope')
                                            ->label('Backup Scope')
                                            ->helperText('Choose what to backup: "Full Stack" backs up both website files and database. "Database Only" dumps only SQL tables.')
                                            ->options(BackupScope::options())
                                            ->default(BackupScope::FullStack->value)
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, Get $get, callable $set) use ($defaultExcludes): void {
                                                if ($state === BackupScope::DatabaseOnly->value) {
                                                    $set('strategy', BackupStrategy::FullOnly->value);
                                                    $set('full_reset_every_n', 1);
                                                } elseif (empty($get('exclude_paths'))) {
                                                    $set('exclude_paths', $defaultExcludes);
                                                }
                                            })
                                            ->required(),

                                        Select::make('strategy')
                                            ->label('Backup Strategy')
                                            ->helperText('"Full Only" takes 100% complete snapshots each run (simplest). "Incremental" takes 1 full backup, then only backs up changed files.')
                                            ->options(BackupStrategy::options())
                                            ->default(BackupStrategy::IncrementalWithPeriodicFull->value)
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                                if ($state === BackupStrategy::FullOnly->value) {
                                                    $set('full_reset_every_n', 1);
                                                } else {
                                                    $set('full_reset_every_n', 7);
                                                }
                                            })
                                            ->required(),

                                        TextInput::make('full_reset_every_n')
                                            ->label('Start New Full Chain Every N Runs')
                                            ->helperText('How many small incremental runs to perform before creating a fresh Base Full Backup (Default: 7 runs).')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default(7)
                                            ->live()
                                            ->visible(fn (Get $get): bool => $get('strategy') !== BackupStrategy::FullOnly->value)
                                            ->required(fn (Get $get): bool => $get('strategy') !== BackupStrategy::FullOnly->value),

                                        Select::make('schedule_frequency')
                                            ->label('Schedule Frequency')
                                            ->helperText('How frequently SiteVaulta should automatically run this backup policy in the background.')
                                            ->options(ScheduleFrequency::options())
                                            ->default(ScheduleFrequency::Daily->value)
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                                if ($state && ($freq = ScheduleFrequency::tryFrom($state))) {
                                                    $set('interval_minutes', $freq->defaultIntervalMinutes());
                                                    $set('cron_expression', $freq->defaultCronExpression());
                                                }
                                            })
                                            ->required(),

                                        TextInput::make('interval_minutes')
                                            ->label('Interval Minutes (I)')
                                            ->helperText('Minutes between automatic runs. (60 = Hourly, 1440 = Daily, 10080 = Weekly). Next run time is calculated from this.')
                                            ->numeric()
                                            ->default(1440)
                                            ->live()
                                            ->readOnly(fn (Get $get): bool => $get('schedule_frequency') !== ScheduleFrequency::CustomCron->value)
                                            ->required(),

                                        TextInput::make('cron_expression')
                                            ->label('Cron Expression')
                                            ->helperText('Standard 5-part Linux cron schedule syntax (e.g., "0 2 * * *" = everyday at 2:00 AM UTC).')
                                            ->default('0 2 * * *')
                                            ->readOnly(fn (Get $get): bool => $get('schedule_frequency') !== ScheduleFrequency::CustomCron->value)
                                            ->required(),

                                        TextInput::make('retention_days')
                                            ->label('Retention Window in Days (R)')
                                            ->helperText('How many days backup files stay in your cloud storage. Files older than this are automatically deleted to save storage space.')
                                            ->numeric()
                                            ->minValue(1)
                                            ->default($defaultRetention)
                                            ->live()
                                            ->required(),

                                        Select::make('download_mode')
                                            ->label('Secure Download Mode')
                                            ->helperText('"One-Time Token" creates single-use expiring download links. "Signed URL" streams direct from cloud storage.')
                                            ->options(DownloadMode::options())
                                            ->default(DownloadMode::OneTimeToken->value)
                                            ->required(),

                                        Select::make('status')
                                            ->label('Policy Status')
                                            ->helperText('"Active" runs automatically on schedule. "Paused" disables automatic runs without deleting existing backups.')
                                            ->options([
                                                'active' => 'Active (Scheduled)',
                                                'paused' => 'Paused',
                                            ])
                                            ->default('active')
                                            ->required(),

                                        Toggle::make('enable_encryption')
                                            ->label('Enable AES-256-GCM Archive Encryption')
                                            ->helperText('When enabled, the zip file is encrypted with military-grade AES-256 before leaving your server.')
                                            ->default($defaultEncryption)
                                            ->live(),

                                        TextInput::make('encryption_passphrase')
                                            ->label('Archive Encryption Passphrase')
                                            ->helperText('Secret password used to lock your backup. ⚠️ WARNING: If you forget this passphrase, the file cannot be decrypted by anyone!')
                                            ->password()
                                            ->revealable()
                                            ->visible(fn (Get $get): bool => (bool) $get('enable_encryption'))
                                            ->columnSpanFull(),

                                        TagsInput::make('exclude_paths')
                                            ->label('Excluded Directories & Glob Patterns')
                                            ->helperText('Directories or file patterns to skip during backup (e.g., node_modules, cache, temp, vendor).')
                                            ->default($defaultExcludes)
                                            ->visible(fn (Get $get): bool => $get('scope') !== BackupScope::DatabaseOnly->value)
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Run Email Alert Notifications (Success & Failure)')
                                    ->description('Set an email address for this policy to receive automatic alerts after each backup run. Choose between your own free Custom SMTP or SiteVaulta System Email Wallet credits, and toggle Success & Failed alerts ON/OFF individually.')
                                    ->columns(2)
                                    ->schema([
                                        Placeholder::make('email_wallet_status_banner')
                                            ->hiddenLabel()
                                            ->columnSpanFull()
                                            ->content(function (): HtmlString {
                                                /** @var Workspace|null $ws */
                                                $ws = Filament::getTenant();
                                                $credits = (int) ($ws?->email_credits_balance ?? 0);
                                                $hasSmtp = $ws?->hasValidCustomSmtp() ?? false;
                                                $walletUrl = $ws ? "/app/{$ws->slug}/email-wallet" : '#';
                                                $creditColor = $credits > 10 ? 'var(--sv-success)' : ($credits > 0 ? 'var(--sv-warning)' : 'var(--sv-danger)');
                                                $smtpBadge = $hasSmtp
                                                    ? "<span style='color:var(--sv-success);font-weight:700;'>✅ Configured (Free Unlimited)</span>"
                                                    : "<span style='color:var(--sv-warning);font-weight:700;'>⚠️ Not Set</span>";

                                                return new HtmlString(
                                                    "<div style='padding:12px 14px;border-radius:8px;background:var(--sv-surface);border:1px solid var(--sv-border);font-size:13px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;'>".
                                                    "<div><strong>💳 Digital Email Wallet:</strong> <span style='color:{$creditColor};font-weight:800;'>{$credits} Credits</span> &nbsp;|&nbsp; <strong>🔧 Custom SMTP:</strong> {$smtpBadge}</div>".
                                                    "<a href='".e($walletUrl)."' style='color:var(--sv-primary);font-weight:700;text-decoration:underline;'>Manage Email Wallet, Custom SMTP &amp; Referrals &rarr;</a>".
                                                    '</div>'
                                                );
                                            }),

                                        Select::make('email_provider_mode')
                                            ->label('Email Delivery Method (SMTP vs System Email Package)')
                                            ->options(function (): array {
                                                /** @var Workspace|null $ws */
                                                $ws = Filament::getTenant();
                                                $credits = (int) ($ws?->email_credits_balance ?? 0);
                                                $defaultLabel = ($ws?->email_provider_mode === 'custom_smtp')
                                                    ? 'My Custom SMTP — Free'
                                                    : "System Email Wallet — {$credits} Credits Available";

                                                return [
                                                    'workspace_default' => "Use Workspace Default ({$defaultLabel} — Auto-Fallback Enabled)",
                                                    'custom_smtp' => '🔧 My Own Custom SMTP First (Free — Auto-Falls Back to System Email Wallet if SMTP Not Set)',
                                                    'system_wallet' => "💳 SiteVaulta System Email Package First ({$credits} Credits — Auto-Falls Back to Custom SMTP if 0 Credits)",
                                                ];
                                            })
                                            ->default('workspace_default')
                                            ->helperText('Smart Fallback: If your selected delivery option is unavailable (e.g., 0 wallet credits or Custom SMTP not set), SiteVaulta automatically sends using whichever option is available.')
                                            ->columnSpanFull(),

                                        TextInput::make('notification_email')
                                            ->label('Notification Email Address (Per Run Alert)')
                                            ->email()
                                            ->placeholder(fn (): string => auth()->user()?->email ?: 'alerts@youragency.com')
                                            ->default(fn (): ?string => auth()->user()?->email)
                                            ->maxLength(191)
                                            ->helperText('Emails for every run of this policy will be sent to this address based on the toggles below.')
                                            ->columnSpanFull(),

                                        Toggle::make('notify_on_success')
                                            ->label('Send Email on Backup Success')
                                            ->helperText('Turn ON to send an email when a backup run completes and verifies successfully.')
                                            ->default(true)
                                            ->onColor('success'),

                                        Toggle::make('notify_on_failure')
                                            ->label('Send Email on Backup Failed')
                                            ->helperText('Turn ON to send an immediate alert email if a backup run fails.')
                                            ->default(true)
                                            ->onColor('danger'),
                                    ]),
                            ]),

                        // RIGHT COLUMN (5/12): High-Contrast Live Storage & Transfer Forecaster
                        Section::make('📊 Pre-Backup Storage & Transfer Forecaster')
                            ->description('Real-time projection based on your selected scope, strategy, schedule, and retention window.')
                            ->columnSpan(5)
                            ->schema([
                                Placeholder::make('live_storage_forecast')
                                    ->hiddenLabel()
                                    ->content(function (Get $get): HtmlString {
                                        $websiteId = $get('website_id');
                                        $storageConnId = $get('storage_connection_id');
                                        $website = $websiteId ? Website::find($websiteId) : null;
                                        $storageConn = $storageConnId ? StorageConnection::find($storageConnId) : null;

                                        $scope = BackupScope::tryFrom((string) $get('scope')) ?? BackupScope::FullStack;
                                        $strategy = BackupStrategy::tryFrom((string) $get('strategy')) ?? BackupStrategy::IncrementalWithPeriodicFull;
                                        $isFullOnly = ($strategy === BackupStrategy::FullOnly);

                                        $forecast = StorageForecastService::calculate(
                                            website: $website,
                                            scope: $scope,
                                            strategy: $strategy,
                                            intervalMinutes: max(15, (int) ($get('interval_minutes') ?: 1440)),
                                            retentionDays: max(1, (int) ($get('retention_days') ?: 14)),
                                            fullResetEveryN: $isFullOnly ? 1 : max(1, (int) ($get('full_reset_every_n') ?: 7)),
                                            storage: $storageConn,
                                        );

                                        $badgeColor = match ($forecast['quota_status']) {
                                            'exceeded' => 'var(--sv-danger)',
                                            'warning' => 'var(--sv-warning)',
                                            default => 'var(--sv-success)',
                                        };

                                        $incrementalRow = $isFullOnly
                                            ? "<li style='margin-bottom:6px;'><strong style='color:var(--sv-muted);'>Chain Strategy:</strong> <span style='color:var(--sv-info);font-weight:700;'>100% Standalone Full Backups (No Incrementals)</span></li>"
                                            : "<li style='margin-bottom:6px;'><strong style='color:var(--sv-muted);'>Single Incremental Delta Size:</strong> <span style='color:var(--sv-text);font-weight:700;'>{$forecast['incremental_formatted']}</span></li>";

                                        $pointsBreakdown = $isFullOnly
                                            ? "{$forecast['total_backups_in_window']} Full Backups"
                                            : "{$forecast['total_backups_in_window']} ({$forecast['full_backups_count']} Full + {$forecast['incremental_backups_count']} Incremental)";

                                        return new HtmlString(
                                            "<div style='padding:18px;border-radius:10px;border:1px solid var(--sv-border);background:var(--sv-bg);color:var(--sv-text);font-size:13.5px;line-height:1.75;'>".
                                            "<div style='padding:10px 12px;border-radius:8px;background:var(--sv-surface);border-left:4px solid {$badgeColor};font-weight:700;color:{$badgeColor};margin-bottom:14px;'>".
                                            'Quota Status: '.strtoupper($forecast['quota_status']).'<br>'.
                                            "<span style='color:var(--sv-text-soft);font-weight:500;font-size:13px;'>".e($forecast['quota_message']).'</span>'.
                                            '</div>'.
                                            "<ul style='margin:0;padding-left:18px;color:var(--sv-text-dim);'>".
                                            "<li style='margin-bottom:6px;'><strong style='color:var(--sv-muted);'>Estimated Raw Source Size:</strong> <span style='color:var(--sv-text);font-weight:700;'>{$forecast['raw_source_formatted']}</span></li>".
                                            "<li style='margin-bottom:6px;'><strong style='color:var(--sv-muted);'>Single Full Backup (Compressed):</strong> <span style='color:var(--sv-primary);font-weight:700;'>{$forecast['first_full_formatted']}</span></li>".
                                            $incrementalRow.
                                            "<li style='margin-bottom:6px;'><strong style='color:var(--sv-muted);'>Retained Recovery Points:</strong> <span style='color:var(--sv-text);font-weight:700;'>{$pointsBreakdown}</span></li>".
                                            "<li style='margin-bottom:6px;'><strong style='color:var(--sv-muted);'>Projected Peak Retained Storage:</strong> <span style='color:var(--sv-success);font-weight:800;font-size:15px;'>{$forecast['projected_peak_storage_formatted']}</span></li>".
                                            "<li><strong style='color:var(--sv-muted);'>30-Day Transfer Volume:</strong> <span style='color:var(--sv-text);font-weight:700;'>{$forecast['monthly_transfer_formatted']}</span></li>".
                                            '</ul></div>'
                                        );
                                    }),
                            ]),
                    ]),
            ]);

    }
}
