<?php

namespace App\Filament\Admin\Pages;

use App\Enums\FileProtocol;
use App\Enums\StorageDriver;
use App\Services\Security\AuditLogService;
use App\Services\Settings\SystemSettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use UnitEnum;

class ManageSystemSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'System & Configuration';

    protected static ?string $navigationLabel = 'Global Dynamic Settings';

    protected static ?string $title = 'Global Dynamic System Settings';

    protected static ?string $slug = 'settings';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill($this->loadSettingsData());
    }

    protected function loadSettingsData(): array
    {
        return [
            // Tab 1: General & Branding
            'app_name' => SystemSettingsService::get('general', 'app_name', 'SiteVaulta'),
            'tagline' => SystemSettingsService::get('general', 'tagline', 'Your Sites. Your Storage. Your Control.'),
            'support_email' => SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com'),
            'support_phone' => SystemSettingsService::get('general', 'support_phone', '+880 1700-000000'),
            'footer_text' => SystemSettingsService::get('general', 'footer_text', 'Enterprise-grade server and database backup automation with zero storage lock-in. Stream encrypted backups directly to your own cloud.'),
            'logo_light_url' => SystemSettingsService::get('general', 'logo_light_url', ''),
            'logo_dark_url' => SystemSettingsService::get('general', 'logo_dark_url', ''),
            'favicon_url' => SystemSettingsService::get('general', 'favicon_url', ''),
            'default_currency' => SystemSettingsService::get('general', 'default_currency', 'BDT'),
            'default_timezone' => SystemSettingsService::get('general', 'default_timezone', 'Asia/Dhaka'),
            'usd_to_bdt_rate' => SystemSettingsService::get('general', 'usd_to_bdt_rate', 120.0),

            // Tab 2: Trial & Subscription Rules
            'default_trial_days' => SystemSettingsService::get('trial_billing', 'default_trial_days', 7),
            'grace_period_days' => SystemSettingsService::get('trial_billing', 'grace_period_days', 3),
            'expired_metadata_retention_days' => SystemSettingsService::get('trial_billing', 'expired_metadata_retention_days', 30),
            'tax_percentage' => SystemSettingsService::get('trial_billing', 'tax_percentage', 0.0),
            'renewal_window_days' => SystemSettingsService::get('trial_billing', 'renewal_window_days', 7),

            // Tab 3: Backup Engine & Forecast Parameters
            'default_compression_ratio' => SystemSettingsService::get('backup_engine', 'default_compression_ratio', 0.60),
            'default_incremental_delta_rate' => SystemSettingsService::get('backup_engine', 'default_incremental_delta_rate', 0.05),
            'default_full_reset_every_n' => SystemSettingsService::get('backup_engine', 'default_full_reset_every_n', 7),
            'default_retention_days' => SystemSettingsService::get('backup_engine', 'default_retention_days', 14),
            'job_timeout_seconds' => SystemSettingsService::get('backup_engine', 'job_timeout_seconds', 7200),
            'max_retry_attempts' => SystemSettingsService::get('backup_engine', 'max_retry_attempts', 3),
            'retry_backoff_seconds' => SystemSettingsService::get('backup_engine', 'retry_backoff_seconds', 60),
            'anomaly_drop_threshold_percent' => SystemSettingsService::get('backup_engine', 'anomaly_drop_threshold_percent', 50),
            'anomaly_spike_threshold_percent' => SystemSettingsService::get('backup_engine', 'anomaly_spike_threshold_percent', 200),
            'default_exclude_paths' => SystemSettingsService::get('backup_engine', 'default_exclude_paths', ['.git', 'node_modules', 'vendor']),

            // Tab 4: Allowed Protocols & Storage Drivers
            'enabled_file_protocols' => SystemSettingsService::get('backup_engine', 'enabled_file_protocols', ['ssh_sftp', 'ftp_ftps', 'cpanel_api', 'vaultix_agent']),
            'enabled_storage_drivers' => SystemSettingsService::get('backup_engine', 'enabled_storage_drivers', ['google_drive', 's3', 'cloudflare_r2', 's3_compatible', 'sftp']),

            // Tab 5: Payment Gateways (SSLCOMMERZ, Stripe, PayPal)
            'sslc_enabled' => SystemSettingsService::get('payment_sslcommerz', 'enabled', true),
            'sslc_sandbox' => SystemSettingsService::get('payment_sslcommerz', 'sandbox_mode', true),
            'sslc_store_name' => SystemSettingsService::get('payment_sslcommerz', 'store_name', 'testcodexi9zk'),
            'sslc_store_id' => SystemSettingsService::get('payment_sslcommerz', 'store_id', 'codex6ae2d3cc64516'),
            'sslc_store_password' => SystemSettingsService::get('payment_sslcommerz', 'store_password', 'qjynKMkpu1gj'),

            'stripe_enabled' => SystemSettingsService::get('payment_stripe', 'enabled', true),
            'stripe_sandbox' => SystemSettingsService::get('payment_stripe', 'sandbox_mode', true),
            'stripe_public_key' => SystemSettingsService::get('payment_stripe', 'public_key', ''),
            'stripe_secret_key' => SystemSettingsService::get('payment_stripe', 'secret_key', ''),
            'stripe_webhook_secret' => SystemSettingsService::get('payment_stripe', 'webhook_secret', ''),

            'paypal_enabled' => SystemSettingsService::get('payment_paypal', 'enabled', true),
            'paypal_sandbox' => SystemSettingsService::get('payment_paypal', 'sandbox_mode', true),
            'paypal_client_id' => SystemSettingsService::get('payment_paypal', 'client_id', ''),
            'paypal_client_secret' => SystemSettingsService::get('payment_paypal', 'client_secret', ''),

            // Tab 6: SMTP & Google OAuth
            'smtp_host' => SystemSettingsService::get('smtp', 'host', ''),
            'smtp_port' => SystemSettingsService::get('smtp', 'port', 587),
            'smtp_username' => SystemSettingsService::get('smtp', 'username', ''),
            'smtp_password' => SystemSettingsService::get('smtp', 'password', ''),
            'smtp_encryption' => SystemSettingsService::get('smtp', 'encryption', 'tls'),
            'smtp_from_address' => SystemSettingsService::get('smtp', 'from_address', 'noreply@sitevaulta.com'),
            'smtp_from_name' => SystemSettingsService::get('smtp', 'from_name', 'SiteVaulta'),
            'google_oauth_client_id' => SystemSettingsService::get('oauth_google', 'client_id', ''),
            'google_oauth_client_secret' => SystemSettingsService::get('oauth_google', 'client_secret', ''),

            // Tab 7: Security & Download Tokens
            'one_time_token_ttl_minutes' => SystemSettingsService::get('security', 'one_time_token_ttl_minutes', 15),
            'presigned_url_ttl_minutes' => SystemSettingsService::get('security', 'presigned_url_ttl_minutes', 15),
            'max_download_resume_retries' => SystemSettingsService::get('security', 'max_download_resume_retries', 3),
            'block_private_ips_ssrf' => SystemSettingsService::get('security', 'block_private_ips_ssrf', true),
            'enforce_2fa' => SystemSettingsService::get('security', 'enforce_2fa', false),
            'ssrf_custom_blocked_ips' => SystemSettingsService::get('security', 'ssrf_custom_blocked_ips', []),

            // Tab 8: Landing Page & Legal CMS
            'hero_title' => SystemSettingsService::get('landing_cms', 'hero_title', 'Automated Website & Server Backups to Your Own Storage'),
            'hero_subtitle' => SystemSettingsService::get('landing_cms', 'hero_subtitle', 'Verify your domain, connect SSH/SFTP, FTP, cPanel, or MySQL/PostgreSQL, and stream encrypted full & incremental backups directly to your Google Drive, Amazon S3, Cloudflare R2, or VPS.'),
            'cta_primary_text' => SystemSettingsService::get('landing_cms', 'cta_primary_text', 'Start 7-Day Free Trial'),
            'terms_content' => SystemSettingsService::get('landing_cms', 'terms_content', "1. Acceptance of Terms\nBy registering a workspace on SiteVaulta, you agree to abide by these Terms of Service. You must verify ownership of any domain before connecting server credentials or running automated backups.\n\n2. Customer-Owned Storage (BYOS)\nSiteVaulta operates on a Bring-Your-Own-Storage architecture. Backup archives are streamed through ephemeral worker staging and uploaded directly to your connected Google Drive, Amazon S3, Cloudflare R2, or SFTP storage."),
            'privacy_content' => SystemSettingsService::get('landing_cms', 'privacy_content', "1. Zero Permanent Plaintext Retention\nSiteVaulta uses ephemeral worker directories (/tmp/sitevaulta) during backup packaging and encryption. Temporary staging directories are automatically wiped immediately after upload.\n\n2. Credential Encryption at Rest\nAll SSH keys, FTP passwords, database credentials, and cloud storage tokens are encrypted at rest in our database using AES-256 encryption."),
            'refund_policy_content' => SystemSettingsService::get('landing_cms', 'refund_policy_content', "1. Free Trial Evaluation\nEvery new workspace receives a full-featured free trial with no credit card required so you can evaluate server connectors, storage adapters, and restore workflows risk-free.\n\n2. Refund Requests\nIf you experience a verified technical issue that prevents backup execution and our engineering support team cannot resolve it within 7 business days of your initial charge, contact support for a full refund review."),
        ];
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('GlobalSystemSettings')
                    ->tabs([
                        Tab::make('General & Branding')
                            ->schema([
                                Section::make('Platform Identity & Regional Defaults')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('app_name')
                                            ->label('Platform Brand Name')
                                            ->required(),
                                        TextInput::make('tagline')
                                            ->label('Tagline')
                                            ->required(),
                                        TextInput::make('support_email')
                                            ->label('Support Contact Email')
                                            ->email()
                                            ->required(),
                                        TextInput::make('support_phone')
                                            ->label('Support Phone Number')
                                            ->placeholder('+880 1700-000000'),
                                        Select::make('default_currency')
                                            ->label('Default System Currency')
                                            ->options(['BDT' => 'BDT (৳)', 'USD' => 'USD ($)'])
                                            ->required(),
                                        Select::make('default_timezone')
                                            ->label('Default Timezone')
                                            ->options([
                                                'Asia/Dhaka' => 'Asia/Dhaka (GMT+6)',
                                                'UTC' => 'UTC',
                                                'America/New_York' => 'America/New_York',
                                                'Europe/London' => 'Europe/London',
                                            ])
                                            ->required(),
                                        TextInput::make('usd_to_bdt_rate')
                                            ->label('USD to BDT Exchange Rate')
                                            ->numeric()
                                            ->step(0.01)
                                            ->required(),
                                        TextInput::make('logo_light_url')
                                            ->label('Light Mode Logo URL / Path')
                                            ->placeholder('/assets/logo-light.svg'),
                                        TextInput::make('logo_dark_url')
                                            ->label('Dark Mode Logo URL / Path')
                                            ->placeholder('/assets/logo-dark.svg'),
                                        TextInput::make('favicon_url')
                                            ->label('Favicon URL / Path')
                                            ->placeholder('/favicon.ico'),
                                        Textarea::make('footer_text')
                                            ->label('Footer Brand Description Text')
                                            ->rows(2)
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Trial & Billing Rules')
                            ->schema([
                                Section::make('Dynamic Trial & Renewal Rules')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('default_trial_days')
                                            ->label('Free Trial Duration (Days)')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(90)
                                            ->required(),
                                        TextInput::make('grace_period_days')
                                            ->label('Renewal Grace Period (Days)')
                                            ->numeric()
                                            ->minValue(0)
                                            ->maxValue(30)
                                            ->required(),
                                        TextInput::make('expired_metadata_retention_days')
                                            ->label('Expired Account Metadata Retention (Days)')
                                            ->helperText('Days to preserve workspace metadata after subscription expires')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(365)
                                            ->required(),
                                        TextInput::make('tax_percentage')
                                            ->label('Tax / VAT Percentage (%)')
                                            ->numeric()
                                            ->step(0.01)
                                            ->required(),
                                        TextInput::make('renewal_window_days')
                                            ->label('Same-Plan Renewal Window (Days Before Expiry)')
                                            ->helperText('Days before expiration when repurchasing the same active plan unlocks (SaaS duplicate charge guard)')
                                            ->numeric()
                                            ->minValue(1)
                                            ->maxValue(30)
                                            ->required(),
                                    ]),
                            ]),

                        Tab::make('Backup Engine & Forecast')
                            ->schema([
                                Section::make('Storage Forecaster & Execution Engine Parameters')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('default_compression_ratio')
                                            ->label('Forecast Default Compression Ratio (0.10 to 1.00)')
                                            ->helperText('e.g., 0.60 means archive is 60% of raw size (40% savings)')
                                            ->numeric()
                                            ->step(0.01)
                                            ->required(),
                                        TextInput::make('default_incremental_delta_rate')
                                            ->label('Forecast Default Incremental Change Rate (0.01 to 1.00)')
                                            ->helperText('e.g., 0.05 means 5% of files change per incremental run')
                                            ->numeric()
                                            ->step(0.01)
                                            ->required(),
                                        TextInput::make('default_full_reset_every_n')
                                            ->label('Default Full Backup Reset Every N Incrementals')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('default_retention_days')
                                            ->label('Default Retention Days')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('job_timeout_seconds')
                                            ->label('Backup Worker Job Timeout (Seconds)')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('max_retry_attempts')
                                            ->label('Max Automatic Retry Attempts')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('retry_backoff_seconds')
                                            ->label('Retry Backoff Time (Seconds)')
                                            ->helperText('Delay in seconds before retrying a failed backup job')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('anomaly_drop_threshold_percent')
                                            ->label('Anomaly Size Drop Warning Threshold (%)')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('anomaly_spike_threshold_percent')
                                            ->label('Anomaly Size Spike Warning Threshold (%)')
                                            ->numeric()
                                            ->required(),
                                        TagsInput::make('default_exclude_paths')
                                            ->label('Global Default Exclude Directories / Patterns')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Protocols & Storage Drivers')
                            ->schema([
                                Section::make('Dynamically Enable / Disable Connectors & Storage Providers')
                                    ->columns(2)
                                    ->schema([
                                        CheckboxList::make('enabled_file_protocols')
                                            ->label('Allowed Server File Protocols')
                                            ->options([
                                                FileProtocol::SshSftp->value => FileProtocol::SshSftp->label(),
                                                FileProtocol::FtpFtps->value => FileProtocol::FtpFtps->label(),
                                                FileProtocol::CPanelApi->value => FileProtocol::CPanelApi->label(),
                                                FileProtocol::VaultixAgent->value => FileProtocol::VaultixAgent->label(),
                                            ])
                                            ->required(),

                                        CheckboxList::make('enabled_storage_drivers')
                                            ->label('Allowed Customer Storage Providers')
                                            ->options(StorageDriver::options())
                                            ->required(),
                                    ]),
                            ]),

                        Tab::make('Payment Gateways')
                            ->schema([
                                Section::make('🇧🇩 SSLCOMMERZ (BDT — bKash, Nagad, Local Cards)')
                                    ->columns(3)
                                    ->schema([
                                        Toggle::make('sslc_enabled')->label('Enable SSLCOMMERZ Gateway'),
                                        Toggle::make('sslc_sandbox')->label('Use SSLCOMMERZ Sandbox Mode')->columnSpan(2),
                                        TextInput::make('sslc_store_name')->label('Store Name'),
                                        TextInput::make('sslc_store_id')->label('Store ID'),
                                        TextInput::make('sslc_store_password')->label('Store Password')->password()->revealable(),
                                    ]),

                                Section::make('🌐 Stripe (USD — International Cards)')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('stripe_enabled')->label('Enable Stripe Gateway'),
                                        Toggle::make('stripe_sandbox')->label('Use Stripe Test / Sandbox Mode'),
                                        TextInput::make('stripe_public_key')
                                            ->label('Publishable Key')
                                            ->placeholder('pk_test_... or pk_live_...'),
                                        TextInput::make('stripe_secret_key')
                                            ->label('Secret Key')
                                            ->placeholder('sk_test_... or sk_live_...')
                                            ->password()
                                            ->revealable(),
                                        TextInput::make('stripe_webhook_secret')
                                            ->label('Webhook Signing Secret')
                                            ->placeholder('whsec_...')
                                            ->password()
                                            ->revealable()
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('🌐 PayPal (USD — International Checkout)')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('paypal_enabled')->label('Enable PayPal Gateway'),
                                        Toggle::make('paypal_sandbox')->label('Use PayPal Sandbox Mode'),
                                        TextInput::make('paypal_client_id')->label('PayPal Client ID'),
                                        TextInput::make('paypal_client_secret')->label('PayPal Client Secret')->password()->revealable(),
                                    ]),
                            ]),

                        Tab::make('SMTP & Google OAuth')
                            ->schema([
                                Section::make('Dynamic Outgoing Mail Server (SMTP)')
                                    ->headerActions([
                                        Action::make('sendTestEmail')
                                            ->label('Send Test Email')
                                            ->icon(Heroicon::OutlinedPaperAirplane)
                                            ->color('info')
                                            ->action(fn () => $this->sendTestEmail()),
                                    ])
                                    ->columns(3)
                                    ->schema([
                                        TextInput::make('smtp_host')->label('SMTP Host'),
                                        TextInput::make('smtp_port')->label('SMTP Port')->numeric(),
                                        Select::make('smtp_encryption')->label('Encryption')->options(['tls' => 'TLS', 'ssl' => 'SSL', '' => 'None']),
                                        TextInput::make('smtp_username')->label('SMTP Username'),
                                        TextInput::make('smtp_password')->label('SMTP Password')->password()->revealable(),
                                        TextInput::make('smtp_from_address')->label('From Email Address')->email(),
                                        TextInput::make('smtp_from_name')->label('From Sender Name'),
                                    ]),

                                Section::make('Google Drive OAuth 2.0 Credentials')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('google_oauth_client_id')->label('Google OAuth Client ID'),
                                        TextInput::make('google_oauth_client_secret')->label('Google OAuth Client Secret')->password()->revealable(),
                                    ]),
                            ]),

                        Tab::make('Download & Security')
                            ->schema([
                                Section::make('Download Token & SSRF Guardrails')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('one_time_token_ttl_minutes')
                                            ->label('One-Time Download Token Expiry (Minutes)')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('presigned_url_ttl_minutes')
                                            ->label('Presigned Cloud URL Expiry (Minutes)')
                                            ->numeric()
                                            ->required(),
                                        TextInput::make('max_download_resume_retries')
                                            ->label('Max Interrupted Download Retries')
                                            ->numeric()
                                            ->required(),
                                        Toggle::make('block_private_ips_ssrf')
                                            ->label('Strictly Block Private / Localhost IP Ranges (SSRF Protection)'),
                                        Toggle::make('enforce_2fa')
                                            ->label('Enforce Two-Factor Authentication (2FA) Across Workspaces'),
                                        TagsInput::make('ssrf_custom_blocked_ips')
                                            ->label('Custom SSRF Blocked IP / Hostname List')
                                            ->placeholder('Add blocked IP or hostname (e.g. 203.0.113.50)')
                                            ->columnSpanFull(),
                                    ]),
                            ]),

                        Tab::make('Landing Page & Legal CMS')
                            ->schema([
                                Section::make('Public Homepage & Legal Pages Dynamic Content')
                                    ->schema([
                                        TextInput::make('hero_title')
                                            ->label('Hero Headline')
                                            ->required(),
                                        Textarea::make('hero_subtitle')
                                            ->label('Hero Subheadline')
                                            ->rows(3)
                                            ->required(),
                                        TextInput::make('cta_primary_text')
                                            ->label('Primary Call-to-Action Button Text')
                                            ->required(),
                                        Textarea::make('terms_content')
                                            ->label('Terms of Service Content (/terms)')
                                            ->rows(5),
                                        Textarea::make('privacy_content')
                                            ->label('Privacy Policy Content (/privacy)')
                                            ->rows(5),
                                        Textarea::make('refund_policy_content')
                                            ->label('Refund & Cancellation Policy Content (/refund-policy)')
                                            ->rows(5),
                                    ]),
                            ]),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')
                                ->label('Save All Dynamic Settings')
                                ->submit('save')
                                ->color('primary'),
                            Action::make('sendSmtpTestEmail')
                                ->label('Send Test Email')
                                ->icon(Heroicon::OutlinedPaperAirplane)
                                ->color('gray')
                                ->action(fn () => $this->sendTestEmail()),
                        ]),
                    ]),
            ]);
    }

    public function sendTestEmail(?string $recipientEmail = null): void
    {
        $state = $this->data ?? [];
        $recipient = $recipientEmail
            ?: (auth()->user()?->email ?? ($state['support_email'] ?? 'admin@sitevaulta.com'));
        $appName = (string) ($state['app_name'] ?? SystemSettingsService::get('general', 'app_name', 'SiteVaulta'));

        if (! empty($state['smtp_host'])) {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => $state['smtp_host'],
                'mail.mailers.smtp.port' => (int) ($state['smtp_port'] ?? 587),
                'mail.mailers.smtp.username' => $state['smtp_username'] ?? null,
                'mail.mailers.smtp.password' => $state['smtp_password'] ?? null,
                'mail.mailers.smtp.encryption' => $state['smtp_encryption'] ?? 'tls',
                'mail.from.address' => $state['smtp_from_address'] ?: config('mail.from.address'),
                'mail.from.name' => $state['smtp_from_name'] ?: $appName,
            ]);
        } else {
            SystemSettingsService::injectRuntimeConfig();
        }

        try {
            Mail::raw(
                "This is an automated SMTP diagnostic test email from {$appName} Global System Settings.",
                function ($message) use ($recipient, $appName): void {
                    $message->to($recipient)
                        ->subject("[{$appName}] Dynamic SMTP Configuration Test");
                }
            );

            AuditLogService::log(
                event: 'system.smtp.test_sent',
                metadata: ['recipient' => $recipient]
            );

            Notification::make()
                ->title("Test email dispatched to {$recipient}.")
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('SMTP Test Failed: '.$e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function save(): void
    {
        $state = $this->form->getState();
        $adminId = auth()->id();

        SystemSettingsService::set('general', 'app_name', $state['app_name'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'tagline', $state['tagline'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'support_email', $state['support_email'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'support_phone', $state['support_phone'] ?? '', 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'footer_text', $state['footer_text'] ?? '', 'text', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'logo_light_url', $state['logo_light_url'] ?? '', 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'logo_dark_url', $state['logo_dark_url'] ?? '', 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'favicon_url', $state['favicon_url'] ?? '', 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'default_currency', $state['default_currency'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'default_timezone', $state['default_timezone'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('general', 'usd_to_bdt_rate', (float) $state['usd_to_bdt_rate'], 'float', false, true, updatedBy: $adminId);

        SystemSettingsService::set('trial_billing', 'default_trial_days', (int) $state['default_trial_days'], 'integer', false, true, updatedBy: $adminId);
        SystemSettingsService::set('trial_billing', 'grace_period_days', (int) $state['grace_period_days'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('trial_billing', 'expired_metadata_retention_days', (int) ($state['expired_metadata_retention_days'] ?? 30), 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('trial_billing', 'tax_percentage', (float) $state['tax_percentage'], 'float', false, true, updatedBy: $adminId);
        SystemSettingsService::set('trial_billing', 'renewal_window_days', (int) ($state['renewal_window_days'] ?? 7), 'integer', false, true, updatedBy: $adminId);

        SystemSettingsService::set('backup_engine', 'default_compression_ratio', (float) $state['default_compression_ratio'], 'float', false, true, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'default_incremental_delta_rate', (float) $state['default_incremental_delta_rate'], 'float', false, true, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'default_full_reset_every_n', (int) $state['default_full_reset_every_n'], 'integer', false, true, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'default_retention_days', (int) $state['default_retention_days'], 'integer', false, true, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'job_timeout_seconds', (int) $state['job_timeout_seconds'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'max_retry_attempts', (int) $state['max_retry_attempts'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'retry_backoff_seconds', (int) ($state['retry_backoff_seconds'] ?? 60), 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'anomaly_drop_threshold_percent', (int) $state['anomaly_drop_threshold_percent'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'anomaly_spike_threshold_percent', (int) $state['anomaly_spike_threshold_percent'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'default_exclude_paths', $state['default_exclude_paths'] ?? [], 'json', false, true, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'enabled_file_protocols', $state['enabled_file_protocols'] ?? [], 'json', false, true, updatedBy: $adminId);
        SystemSettingsService::set('backup_engine', 'enabled_storage_drivers', $state['enabled_storage_drivers'] ?? [], 'json', false, true, updatedBy: $adminId);

        SystemSettingsService::set('payment_sslcommerz', 'enabled', (bool) $state['sslc_enabled'], 'boolean', false, true, updatedBy: $adminId);
        SystemSettingsService::set('payment_sslcommerz', 'sandbox_mode', (bool) $state['sslc_sandbox'], 'boolean', false, false, updatedBy: $adminId);
        SystemSettingsService::set('payment_sslcommerz', 'store_name', $state['sslc_store_name'] ?? 'testcodexi9zk', 'string', false, false, updatedBy: $adminId);
        SystemSettingsService::set('payment_sslcommerz', 'store_id', $state['sslc_store_id'], 'string', true, false, updatedBy: $adminId);
        SystemSettingsService::set('payment_sslcommerz', 'store_password', $state['sslc_store_password'], 'string', true, false, updatedBy: $adminId);

        SystemSettingsService::set('payment_stripe', 'enabled', (bool) $state['stripe_enabled'], 'boolean', false, true, updatedBy: $adminId);
        SystemSettingsService::set('payment_stripe', 'sandbox_mode', (bool) ($state['stripe_sandbox'] ?? true), 'boolean', false, true, updatedBy: $adminId);
        SystemSettingsService::set('payment_stripe', 'public_key', $state['stripe_public_key'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('payment_stripe', 'secret_key', $state['stripe_secret_key'], 'string', true, false, updatedBy: $adminId);
        SystemSettingsService::set('payment_stripe', 'webhook_secret', $state['stripe_webhook_secret'], 'string', true, false, updatedBy: $adminId);

        SystemSettingsService::set('payment_paypal', 'enabled', (bool) $state['paypal_enabled'], 'boolean', false, true, updatedBy: $adminId);
        SystemSettingsService::set('payment_paypal', 'sandbox_mode', (bool) $state['paypal_sandbox'], 'boolean', false, false, updatedBy: $adminId);
        SystemSettingsService::set('payment_paypal', 'client_id', $state['paypal_client_id'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('payment_paypal', 'client_secret', $state['paypal_client_secret'], 'string', true, false, updatedBy: $adminId);

        SystemSettingsService::set('smtp', 'host', $state['smtp_host'], 'string', false, false, updatedBy: $adminId);
        SystemSettingsService::set('smtp', 'port', (int) $state['smtp_port'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('smtp', 'username', $state['smtp_username'], 'string', false, false, updatedBy: $adminId);
        SystemSettingsService::set('smtp', 'password', $state['smtp_password'], 'string', true, false, updatedBy: $adminId);
        SystemSettingsService::set('smtp', 'encryption', $state['smtp_encryption'], 'string', false, false, updatedBy: $adminId);
        SystemSettingsService::set('smtp', 'from_address', $state['smtp_from_address'], 'string', false, false, updatedBy: $adminId);
        SystemSettingsService::set('smtp', 'from_name', $state['smtp_from_name'], 'string', false, false, updatedBy: $adminId);

        SystemSettingsService::set('oauth_google', 'client_id', $state['google_oauth_client_id'], 'string', false, false, updatedBy: $adminId);
        SystemSettingsService::set('oauth_google', 'client_secret', $state['google_oauth_client_secret'], 'string', true, false, updatedBy: $adminId);

        SystemSettingsService::set('security', 'one_time_token_ttl_minutes', (int) $state['one_time_token_ttl_minutes'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('security', 'presigned_url_ttl_minutes', (int) $state['presigned_url_ttl_minutes'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('security', 'max_download_resume_retries', (int) $state['max_download_resume_retries'], 'integer', false, false, updatedBy: $adminId);
        SystemSettingsService::set('security', 'block_private_ips_ssrf', (bool) $state['block_private_ips_ssrf'], 'boolean', false, false, updatedBy: $adminId);
        SystemSettingsService::set('security', 'enforce_2fa', (bool) ($state['enforce_2fa'] ?? false), 'boolean', false, false, updatedBy: $adminId);
        SystemSettingsService::set('security', 'ssrf_custom_blocked_ips', $state['ssrf_custom_blocked_ips'] ?? [], 'json', false, false, updatedBy: $adminId);

        SystemSettingsService::set('landing_cms', 'hero_title', $state['hero_title'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('landing_cms', 'hero_subtitle', $state['hero_subtitle'], 'text', false, true, updatedBy: $adminId);
        SystemSettingsService::set('landing_cms', 'cta_primary_text', $state['cta_primary_text'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('landing_cms', 'terms_content', $state['terms_content'] ?? '', 'text', false, true, updatedBy: $adminId);
        SystemSettingsService::set('landing_cms', 'privacy_content', $state['privacy_content'] ?? '', 'text', false, true, updatedBy: $adminId);
        SystemSettingsService::set('landing_cms', 'refund_policy_content', $state['refund_policy_content'] ?? '', 'text', false, true, updatedBy: $adminId);

        // Keep legacy cms_landing keys synchronized for backward compatibility
        SystemSettingsService::set('cms_landing', 'hero_heading', $state['hero_title'], 'string', false, true, updatedBy: $adminId);
        SystemSettingsService::set('cms_landing', 'hero_subheading', $state['hero_subtitle'], 'text', false, true, updatedBy: $adminId);

        AuditLogService::log(event: 'system.settings.updated');

        Notification::make()
            ->title('All dynamic system settings have been saved and applied immediately.')
            ->success()
            ->send();
    }
}
