<?php

namespace App\Filament\App\Pages\Tenancy;

use App\Enums\DownloadMode;
use App\Models\Workspace;
use App\Services\Security\AuditLogService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Settings\WorkspaceSettingsService;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EditWorkspaceSettings extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return 'Workspace Dynamic Settings';
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Workspace $workspace */
        $workspace = $this->tenant;
        $user = auth()->user();

        $data['date_format'] = WorkspaceSettingsService::get($workspace, 'regional', 'date_format', 'd M Y, H:i');
        $data['enable_2fa'] = (bool) (
            ($user && ! empty($user->two_factor_secret))
            || WorkspaceSettingsService::get($workspace, 'security', 'require_2fa', false)
            || SystemSettingsService::get('security', 'enforce_2fa', false)
        );

        $data['default_exclude_paths'] = WorkspaceSettingsService::get(
            $workspace,
            'backup_defaults',
            'default_exclude_paths',
            ['.git', 'node_modules', 'vendor', 'storage/logs/*.log', '*.tmp']
        );
        $data['default_retention_days'] = WorkspaceSettingsService::get($workspace, 'backup_defaults', 'default_retention_days', 14);
        $data['default_enable_encryption'] = WorkspaceSettingsService::get($workspace, 'backup_defaults', 'default_enable_encryption', true);
        $data['default_download_mode'] = WorkspaceSettingsService::get($workspace, 'backup_defaults', 'default_download_mode', DownloadMode::OneTimeToken->value);

        $data['notify_on_failure'] = WorkspaceSettingsService::get($workspace, 'notifications', 'notify_on_failure', true);
        $data['notify_on_anomaly'] = WorkspaceSettingsService::get($workspace, 'notifications', 'notify_on_anomaly', true);
        $data['notify_on_success'] = WorkspaceSettingsService::get($workspace, 'notifications', 'notify_on_success', false);
        $data['quiet_hours_enabled'] = WorkspaceSettingsService::get($workspace, 'notifications', 'quiet_hours_enabled', false);
        $data['quiet_hours_start'] = WorkspaceSettingsService::get($workspace, 'notifications', 'quiet_hours_start', '23:00');
        $data['quiet_hours_end'] = WorkspaceSettingsService::get($workspace, 'notifications', 'quiet_hours_end', '07:00');

        $whitelabel = $workspace->whitelabel_config ?? [];
        $data['agency_company_name'] = $whitelabel['company_name'] ?? $workspace->name;
        $data['agency_logo_url'] = $whitelabel['logo_url'] ?? '';
        $data['agency_brand_color'] = $whitelabel['brand_color'] ?? '#4F46E5';
        $data['agency_support_email'] = $whitelabel['support_email'] ?? auth()->user()?->email;
        $data['agency_report_footer'] = $whitelabel['report_footer'] ?? 'Automated Website Backup Verification Report';

        return $data;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('WorkspaceSettingsTabs')
                    ->tabs([
                        Tab::make('General & Regional')
                            ->schema([
                                Section::make('Workspace Identity & Regional Preferences')
                                    ->description('All dates, schedules, and billing currencies in this workspace dynamically use these settings.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Workspace Name')
                                            ->required()
                                            ->maxLength(120),

                                        TextInput::make('slug')
                                            ->label('Workspace Slug')
                                            ->required()
                                            ->maxLength(140),

                                        Select::make('billing_currency')
                                            ->label('Billing Currency')
                                            ->options([
                                                'BDT' => '🇧🇩 BDT (৳) — SSLCOMMERZ (bKash/Nagad/Cards)',
                                                'USD' => '🌐 USD ($) — Stripe / PayPal',
                                            ])
                                            ->required(),

                                        Select::make('timezone')
                                            ->label('Workspace Timezone')
                                            ->options([
                                                'Asia/Dhaka' => 'Asia/Dhaka (GMT+6)',
                                                'UTC' => 'UTC (GMT+0)',
                                                'America/New_York' => 'America/New_York (EST)',
                                                'Europe/London' => 'Europe/London (GMT)',
                                                'Asia/Kolkata' => 'Asia/Kolkata (IST)',
                                                'Asia/Singapore' => 'Asia/Singapore (SGT)',
                                            ])
                                            ->searchable()
                                            ->required(),

                                        Select::make('date_format')
                                            ->label('Preferred Date & Time Format')
                                            ->options([
                                                'd M Y, H:i' => '04 Nov 2026, 22:30 (d M Y, H:i)',
                                                'Y-m-d H:i' => '2026-11-04 22:30 (ISO Y-m-d H:i)',
                                                'm/d/Y h:i A' => '11/04/2026 10:30 PM (US 12h)',
                                                'd/m/Y H:i' => '04/11/2026 22:30 (d/m/Y H:i)',
                                            ])
                                            ->required(),

                                        Toggle::make('enable_2fa')
                                            ->label('Enable Two-Factor Authentication (TOTP 2FA)')
                                            ->helperText('Protects your account and workspace with an encrypted TOTP 2FA secret key.'),
                                    ]),
                            ]),

                        Tab::make('Default Backup Rules')
                            ->schema([
                                Section::make('Dynamic Defaults for New Backup Policies')
                                    ->description('Every new backup policy created in this workspace will automatically pre-fill with these dynamic values.')
                                    ->columns(2)
                                    ->schema([
                                        TagsInput::make('default_exclude_paths')
                                            ->label('Default Exclude Directories / File Patterns')
                                            ->placeholder('Add folder or glob pattern (e.g., node_modules)')
                                            ->columnSpanFull(),

                                        TextInput::make('default_retention_days')
                                            ->label('Default Retention Period (Days)')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(365)
                                            ->required(),

                                        Select::make('default_download_mode')
                                            ->label('Default Secure Download Mode')
                                            ->options(DownloadMode::options())
                                            ->required(),

                                        Toggle::make('default_enable_encryption')
                                            ->label('Enable AES-256-GCM Archive Encryption by Default')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Notification Preferences')
                            ->schema([
                                Section::make('Default Alert Triggers')
                                    ->description('Choose which backup lifecycle events trigger notifications across your connected channels.')
                                    ->schema([
                                        Toggle::make('notify_on_failure')
                                            ->label('Notify Immediately When a Backup Fails'),
                                        Toggle::make('notify_on_anomaly')
                                            ->label('Notify When Smart Anomaly Detection Flags Unusual Archive Size Changes'),
                                        Toggle::make('notify_on_success')
                                            ->label('Notify After Every Successful Backup Run'),
                                    ]),

                                Section::make('Quiet Hours (Do Not Disturb Window)')
                                    ->description('Suppress non-critical notifications (such as routine success alerts) during your off-hours while still delivering critical failure alerts.')
                                    ->columns(3)
                                    ->schema([
                                        Toggle::make('quiet_hours_enabled')
                                            ->label('Enable Quiet Hours')
                                            ->columnSpanFull(),
                                        TextInput::make('quiet_hours_start')
                                            ->label('Quiet Hours Start (24h HH:MM)')
                                            ->placeholder('23:00'),
                                        TextInput::make('quiet_hours_end')
                                            ->label('Quiet Hours End (24h HH:MM)')
                                            ->placeholder('07:00'),
                                    ]),
                            ]),

                        Tab::make('Agency White-Label Branding')
                            ->schema([
                                Section::make('Client PDF Report Branding')
                                    ->description('Customize the branding shown on automated PDF backup health reports sent to your clients.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('agency_company_name')
                                            ->label('Agency / Brand Name')
                                            ->maxLength(150),

                                        TextInput::make('agency_logo_url')
                                            ->label('Agency Logo URL / Path')
                                            ->placeholder('https://agency.example.com/logo.png')
                                            ->maxLength(255),

                                        ColorPicker::make('agency_brand_color')
                                            ->label('Primary Brand Color'),

                                        TextInput::make('agency_support_email')
                                            ->label('Support Contact Email on Reports')
                                            ->email()
                                            ->maxLength(191),

                                        TextInput::make('agency_report_footer')
                                            ->label('Custom PDF Footer Text')
                                            ->maxLength(255)
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Workspace $record */
        $record->update([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'billing_currency' => $data['billing_currency'],
            'timezone' => $data['timezone'],
            'whitelabel_config' => [
                'company_name' => $data['agency_company_name'] ?? $record->name,
                'logo_url' => $data['agency_logo_url'] ?? null,
                'brand_color' => $data['agency_brand_color'] ?? '#4F46E5',
                'support_email' => $data['agency_support_email'] ?? null,
                'report_footer' => $data['agency_report_footer'] ?? null,
            ],
        ]);

        $enable2fa = (bool) ($data['enable_2fa'] ?? false)
            || (bool) SystemSettingsService::get('security', 'enforce_2fa', false);

        $user = auth()->user();
        if ($user) {
            if ($enable2fa && empty($user->two_factor_secret)) {
                $user->update([
                    'two_factor_secret' => Str::upper(Str::random(32)),
                    'two_factor_confirmed_at' => now(),
                ]);
            } elseif (! $enable2fa && ! empty($user->two_factor_secret)) {
                $user->update([
                    'two_factor_secret' => null,
                    'two_factor_confirmed_at' => null,
                ]);
            }
        }

        WorkspaceSettingsService::set($record, 'regional', 'timezone', $data['timezone'], 'string');
        WorkspaceSettingsService::set($record, 'regional', 'currency', $data['billing_currency'], 'string');
        WorkspaceSettingsService::set($record, 'regional', 'date_format', $data['date_format'] ?? 'd M Y, H:i', 'string');
        WorkspaceSettingsService::set($record, 'security', 'require_2fa', $enable2fa, 'boolean');
        WorkspaceSettingsService::set($record, 'backup_defaults', 'default_exclude_paths', $data['default_exclude_paths'] ?? [], 'json');
        WorkspaceSettingsService::set($record, 'backup_defaults', 'default_retention_days', (int) ($data['default_retention_days'] ?? 14), 'integer');
        WorkspaceSettingsService::set($record, 'backup_defaults', 'default_enable_encryption', (bool) ($data['default_enable_encryption'] ?? false), 'boolean');
        WorkspaceSettingsService::set($record, 'backup_defaults', 'default_download_mode', $data['default_download_mode'] ?? 'one_time_token', 'string');
        WorkspaceSettingsService::set($record, 'notifications', 'notify_on_failure', (bool) ($data['notify_on_failure'] ?? true), 'boolean');
        WorkspaceSettingsService::set($record, 'notifications', 'notify_on_anomaly', (bool) ($data['notify_on_anomaly'] ?? true), 'boolean');
        WorkspaceSettingsService::set($record, 'notifications', 'notify_on_success', (bool) ($data['notify_on_success'] ?? false), 'boolean');
        WorkspaceSettingsService::set($record, 'notifications', 'quiet_hours_enabled', (bool) ($data['quiet_hours_enabled'] ?? false), 'boolean');
        WorkspaceSettingsService::set($record, 'notifications', 'quiet_hours_start', $data['quiet_hours_start'] ?? '23:00', 'string');
        WorkspaceSettingsService::set($record, 'notifications', 'quiet_hours_end', $data['quiet_hours_end'] ?? '07:00', 'string');

        AuditLogService::log(
            event: 'workspace.settings.updated',
            auditable: $record,
            workspaceId: $record->id
        );

        return $record;
    }
}
