<?php

namespace App\Filament\App\Resources\Websites\Schemas;

use App\Models\VerifiedDomain;
use App\Models\Website;
use App\Models\Workspace;
use App\Rules\UniqueWorkspaceTarget;
use App\Services\Verification\DomainVerificationService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class WebsiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        // LEFT COLUMN (7/12): Website Registration Form & Live Uptime Monitoring
                        Grid::make(1)
                            ->columnSpan(7)
                            ->schema([
                                Section::make('Website Details & Source Size Estimates')
                                    ->description('Register your website URL first, then verify domain ownership via DNS TXT, HTML File, or Meta Tag.')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('name')
                                            ->label('Website Label / Project Name')
                                            ->placeholder('e.g., Client E-Commerce Store')
                                            ->required()
                                            ->maxLength(120),

                                        TextInput::make('url')
                                            ->label('Website Full URL')
                                            ->placeholder('https://example.com')
                                            ->url()
                                            ->required()
                                            ->maxLength(255)
                                            // Each domain / sub-domain only once per workspace (www. ignored).
                                            ->rule(fn (?Website $record): UniqueWorkspaceTarget => new UniqueWorkspaceTarget(
                                                Filament::getTenant() instanceof Workspace ? Filament::getTenant() : null,
                                                UniqueWorkspaceTarget::WEBSITES,
                                                $record?->id,
                                            )),

                                        Select::make('environment')
                                            ->label('Environment')
                                            ->options([
                                                'production' => 'Production',
                                                'staging' => 'Staging',
                                                'development' => 'Development',
                                            ])
                                            ->default('production')
                                            ->required(),

                                        Select::make('status')
                                            ->label('Operational Status')
                                            ->options([
                                                'active' => 'Active',
                                                'paused' => 'Paused',
                                            ])
                                            ->default('active')
                                            ->required(),

                                        TextInput::make('known_files_size_bytes')
                                            ->label('Estimated Website Files Size (Bytes)')
                                            ->helperText('500 MB = 524288000 | 1 GB = 1073741824')
                                            ->numeric()
                                            ->default(524288000),

                                        TextInput::make('known_db_size_bytes')
                                            ->label('Estimated Database Size (Bytes)')
                                            ->helperText('100 MB = 104857600')
                                            ->numeric()
                                            ->default(104857600),
                                    ]),

                                Section::make('📡 Live Domain & Server Uptime Monitoring + Owner Alerts')
                                    ->description('Automatically monitor whether your verified domain and connected server are live. If the website or server goes down, an instant alert email is sent to the owner.')
                                    ->columns(2)
                                    ->schema([
                                        Toggle::make('uptime_monitoring_enabled')
                                            ->label('Enable Automatic Live Domain & Server Uptime Check')
                                            ->default(true)
                                            ->onColor('success')
                                            ->columnSpanFull(),

                                        TextInput::make('uptime_alert_email')
                                            ->label('Owner Alert Email Address (For Downtime & Recovery)')
                                            ->email()
                                            ->placeholder(fn (): string => auth()->user()?->email ?: 'owner@youragency.com')
                                            ->default(fn (): ?string => auth()->user()?->email)
                                            ->helperText('If left blank, alerts will be sent to the Workspace Owner email.')
                                            ->columnSpanFull(),

                                        Select::make('uptime_email_provider_mode')
                                            ->label('Preferred Email Delivery Method (With Smart Auto-Fallback)')
                                            ->options(function (): array {
                                                /** @var Workspace|null $ws */
                                                $ws = Filament::getTenant();
                                                $credits = (int) ($ws?->email_credits_balance ?? 0);
                                                $hasSmtp = $ws?->hasValidCustomSmtp() ?? false;

                                                return [
                                                    'workspace_default' => 'Use Workspace Default (Auto-Fallback Enabled)',
                                                    'custom_smtp' => '🔧 My Own Custom SMTP First (Free — Auto-Falls Back to System Wallet if SMTP Not Set)',
                                                    'system_wallet' => "💳 System Email Package First ({$credits} Credits — Auto-Falls Back to Custom SMTP if 0 Credits)",
                                                ];
                                            })
                                            ->default('workspace_default')
                                            ->helperText('Smart Fallback: If your selected option (e.g., Custom SMTP or System Email Wallet Credits) is not available, SiteVaulta will automatically send using whichever option is available so you never miss a Down alert!')
                                            ->columnSpanFull(),

                                        Toggle::make('notify_on_downtime')
                                            ->label('Send Alert When Domain or Server Goes DOWN')
                                            ->default(true)
                                            ->onColor('danger'),

                                        Toggle::make('notify_on_recovery')
                                            ->label('Send Alert When Domain or Server RECOVERS (Back Online)')
                                            ->default(true)
                                            ->onColor('success'),

                                        Toggle::make('notify_on_malware_detected')
                                            ->label('🛡️ Send Read-Only Security Alert When Malware / Web-Shell / Exposed File is Detected')
                                            ->helperText('100% Read-Only Guarantee: Only reports exact suspicious file path(s) to your email. SiteVaulta NEVER deletes or modifies any file on your live server.')
                                            ->default(true)
                                            ->onColor('warning')
                                            ->columnSpanFull(),

                                        Toggle::make('notify_on_ssl_expiry')
                                            ->label('🔒 Send SSL Certificate Expiry Auto-Reminder')
                                            ->helperText('Alerts you via Email and In-App Notification Bell before your HTTPS SSL certificate expires.')
                                            ->default(true)
                                            ->onColor('info'),

                                        Select::make('ssl_expiry_warning_days')
                                            ->label('SSL Expiry Reminder Threshold')
                                            ->options([
                                                30 => '30 Days Before Expiry',
                                                14 => '14 Days Before Expiry (Recommended)',
                                                7 => '7 Days Before Expiry',
                                                3 => '3 Days Before Expiry',
                                            ])
                                            ->default(14),
                                    ]),
                            ]),

                        // RIGHT COLUMN (5/12): Step-by-Step Domain Verification Guide
                        Section::make('📖 Domain Ownership Verification Guide')
                            ->description('Why & how to verify your domain before connecting servers.')
                            ->columnSpan(5)
                            ->schema([
                                Placeholder::make('verification_challenge_details')
                                    ->hiddenLabel()
                                    ->content(function (?Website $record): HtmlString {
                                        $boxStyle = 'padding:16px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);font-size:13.5px;line-height:1.7;';
                                        $codeStyle = 'display:block;background:var(--sv-surface);color:var(--sv-code);padding:8px 10px;border-radius:6px;font-family:monospace;font-size:12px;margin:6px 0;border:1px solid var(--sv-border);word-break:break-all;';

                                        if (! $record) {
                                            return new HtmlString(
                                                "<div style='{$boxStyle}'>".
                                                "<div style='font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:8px;'>🔒 Mandatory Domain Ownership Gate</div>".
                                                "<p style='color:var(--sv-text-dim);margin-bottom:10px;'>After saving this website, SiteVaulta will generate a unique cryptographic verification token. You can verify ownership using any 1 of these 3 methods:</p>".
                                                "<ol style='padding-left:18px;color:var(--sv-text-soft);'>".
                                                '<li><strong>DNS TXT Record (Cloudflare / Route53 / Namecheap):</strong> Add a TXT record for <code>_sitevaulta-challenge.yourdomain.com</code>.</li>'.
                                                '<li><strong>HTML Verification File:</strong> Upload <code>sitevaulta-verify.txt</code> to your web root (<code>/sitevaulta-verify.txt</code>) or to <code>/.well-known/sitevaulta-verify.txt</code>.</li>'.
                                                '<li><strong>Homepage Meta Tag:</strong> Add the <code>&lt;meta name="sitevaulta-verification"&gt;</code> tag inside your homepage <code>&lt;head&gt;</code>.</li>'.
                                                '</ol></div>'
                                            );
                                        }

                                        $challenge = DomainVerificationService::ensureChallengeExists($record);

                                        return new HtmlString(sprintf(
                                            '<div style="%s">'
                                            .'<div style="font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:10px;">Choose Any 1 Method Below to Verify</div>'
                                            .'<div style="margin-bottom:10px;"><strong>Method A (DNS TXT Record):</strong><code style="%s">Host: %s<br>Value: %s</code></div>'
                                            .'<div style="margin-bottom:10px;"><strong>Method B (HTML File):</strong><code style="%s">URL: %s%s<br>or (web root, no .well-known folder): %s/sitevaulta-verify.txt<br>Content: %s</code></div>'
                                            .'<div><strong>Method C (Homepage Meta Tag):</strong><code style="%s">&lt;meta name="%s" content="%s"&gt;</code></div>'
                                            .'</div>',
                                            $boxStyle,
                                            $codeStyle,
                                            e($challenge->dnsRecordName()),
                                            e($challenge->token),
                                            $codeStyle,
                                            e(rtrim($record->url, '/')),
                                            e(VerifiedDomain::FILE_PATH),
                                            e(rtrim($record->url, '/')),
                                            e($challenge->token),
                                            $codeStyle,
                                            e(VerifiedDomain::META_TAG_NAME),
                                            e($challenge->token)
                                        ));
                                    }),
                            ]),
                    ]),
            ]);

    }
}
