<?php

namespace App\Filament\App\Resources\StorageConnections\Schemas;

use App\Enums\StorageDriver;
use App\Services\Settings\SystemSettingsService;
use App\Services\Storage\StorageAdapterManager;
use Filament\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Livewire\Component;

class StorageConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        // LEFT COLUMN (7/12): Storage Connection Form
                        Section::make('Storage Configuration (BYOS)')
                            ->description('Select your storage provider, enter your credentials, and click Check Connection to verify.')
                            ->columnSpan(7)
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Storage Connection Name')
                                    ->placeholder('e.g., My Google Drive, Production AWS S3, Cloudflare R2, or Backup VPS')
                                    ->required()
                                    ->maxLength(120),

                                Select::make('driver')
                                    ->label('Storage Provider')
                                    ->options(function (): array {
                                        $enabled = (array) SystemSettingsService::get(
                                            'backup_engine',
                                            'enabled_storage_drivers',
                                            ['google_drive', 's3', 'cloudflare_r2', 'sftp', 's3_compatible']
                                        );

                                        return collect(StorageDriver::options())
                                            ->filter(fn ($label, $key) => in_array($key, $enabled, true))
                                            ->all();
                                    })
                                    ->default(StorageDriver::GoogleDrive->value)
                                    ->live()
                                    ->required(),

                                // =========================================================
                                // 1. GOOGLE DRIVE FIELDS
                                // Client ID, Client Secret, Refresh Token (+ Update Token button), Folder ID / Folder Name
                                // =========================================================
                                TextInput::make('credentials.client_id')
                                    ->label('Client ID')
                                    ->placeholder('e.g., 1234567890-abcdefg.apps.googleusercontent.com')
                                    ->live(onBlur: true)
                                    ->required(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->columnSpan(1),

                                TextInput::make('credentials.client_secret')
                                    ->label('Client Secret')
                                    ->password()
                                    ->revealable()
                                    ->placeholder('e.g., GOCSPX-xxxxxxxxxxxxxxxxxxxx')
                                    ->live(onBlur: true)
                                    ->required(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->columnSpan(1),

                                TextInput::make('credentials.refresh_token')
                                    ->label('Refresh Token')
                                    ->placeholder('Paste Google Refresh Token here, or use the buttons below')
                                    ->helperText(function () {
                                        $callbackUrl = url('/auth/google/callback');

                                        return new HtmlString(
                                            "<div style='font-size:12px;line-height:1.6;margin-top:4px;color:var(--sv-text-soft);'>".
                                            '<strong>For Real Google Drive Upload:</strong><br>'.
                                            "1. Ensure this redirect URI is in Google Cloud Console: <code style='background:var(--sv-surface);padding:2px 6px;border-radius:4px;border:1px solid var(--sv-border);color:var(--sv-primary);font-weight:700;'>{$callbackUrl}</code><br>".
                                            '2. Click <strong>🔗 Authorize with Google Account</strong> to sign in and grant access, then click <strong>📥 Import Authorized Token</strong> (or paste token directly).'.
                                            '</div>'
                                        );
                                    })
                                    ->required(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->columnSpanFull(),

                                Actions::make([
                                    Action::make('authorizeGoogle')
                                        ->label('🔗 Authorize with Google Account')
                                        ->button()
                                        ->color('success')
                                        ->action(function (Get $get, Component $livewire): void {
                                            $clientId = trim((string) $get('credentials.client_id'));
                                            $clientSecret = trim((string) $get('credentials.client_secret'));

                                            if ($clientId === '' || $clientSecret === '') {
                                                Notification::make()
                                                    ->title('Client ID & Client Secret Required')
                                                    ->body('Please enter your Google Client ID and Client Secret in the fields above first before clicking Authorize with Google.')
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            $url = url('/auth/google/redirect').'?'.http_build_query([
                                                'client_id' => $clientId,
                                                'client_secret' => $clientSecret,
                                                'redirect_uri' => url('/auth/google/callback'),
                                            ]);

                                            $livewire->js('window.open('.json_encode($url).", '_blank');");
                                        }),

                                    Action::make('importSessionToken')
                                        ->label('📥 Import Authorized Token')
                                        ->button()
                                        ->color('primary')
                                        ->action(function (Get $get, Set $set): void {
                                            $clientId = trim((string) $get('credentials.client_id'));
                                            $clientSecret = trim((string) $get('credentials.client_secret'));

                                            if ($clientId === '' || $clientSecret === '') {
                                                Notification::make()
                                                    ->title('Client ID & Client Secret Required')
                                                    ->body('Please enter your Google Client ID and Client Secret first before importing token.')
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            $sessionToken = (string) session('google_oauth_refresh_token', '');
                                            if ($sessionToken !== '') {
                                                $set('credentials.refresh_token', $sessionToken);

                                                Notification::make()
                                                    ->title('OAuth Refresh Token Imported!')
                                                    ->body('Official OAuth Refresh Token has been imported from your Google authorization session.')
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title('No Authorized Token Found in Session')
                                                    ->body('Please click "🔗 Authorize with Google Account" first to sign in and grant access, then click Import Token. Or paste your Refresh Token manually.')
                                                    ->warning()
                                                    ->send();
                                            }
                                        }),

                                    Action::make('generateDemoToken')
                                        ->label('⚡ Instant Demo Token (Auto-Generate)')
                                        ->button()
                                        ->color('gray')
                                        ->action(function (Get $get, Set $set): void {
                                            $clientId = trim((string) $get('credentials.client_id'));
                                            $clientSecret = trim((string) $get('credentials.client_secret'));

                                            if ($clientId === '' || $clientSecret === '') {
                                                Notification::make()
                                                    ->title('Client ID & Client Secret Required')
                                                    ->body('Please enter your Google Client ID and Client Secret first.')
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            $hash = hash_hmac('sha256', $clientId.'|'.now()->format('YmdHis'), $clientSecret);
                                            $token = '1//04sv_'.substr(base64_encode(hex2bin($hash)), 0, 43);
                                            $set('credentials.refresh_token', $token);

                                            Notification::make()
                                                ->title('Demo Token Populated')
                                                ->body('A local demo token has been automatically generated into the field (Note: Demo token cannot upload to real Google Drive).')
                                                ->info()
                                                ->send();
                                        }),
                                ])
                                    ->visible(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->columnSpanFull(),

                                TextInput::make('credentials.folder_id')
                                    ->label('Folder Name or Folder ID')
                                    ->default('sitevaulta-backups')
                                    ->placeholder('e.g., sitevaulta-backups or 1BxiMVs0XRA5nFMdKvBdBZjgmUUqptlbs74OgvE2upms')
                                    ->helperText('Enter a Folder Name (e.g., sitevaulta-backups) or paste a Google Drive Folder ID from the folder URL.')
                                    ->required(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'google_drive')
                                    ->columnSpanFull(),

                                // =========================================================
                                // 2. AWS S3 & CLOUDFLARE R2 & S3-COMPATIBLE FIELDS
                                // AWS S3: Access Key, Secret Key, Bucket, Region
                                // Cloudflare R2: Access Key, Secret Key, Endpoint (URL), Bucket
                                // =========================================================
                                TextInput::make('credentials.access_key_id')
                                    ->label('Access Key')
                                    ->placeholder('Paste your Access Key ID')
                                    ->required(fn (Get $get): bool => in_array($get('driver'), ['s3', 'cloudflare_r2', 's3_compatible'], true))
                                    ->visible(fn (Get $get): bool => in_array($get('driver'), ['s3', 'cloudflare_r2', 's3_compatible'], true))
                                    ->columnSpan(1),

                                TextInput::make('credentials.secret_access_key')
                                    ->label('Secret Key')
                                    ->password()
                                    ->revealable()
                                    ->placeholder('Paste your Secret Access Key')
                                    ->required(fn (Get $get): bool => in_array($get('driver'), ['s3', 'cloudflare_r2', 's3_compatible'], true))
                                    ->visible(fn (Get $get): bool => in_array($get('driver'), ['s3', 'cloudflare_r2', 's3_compatible'], true))
                                    ->columnSpan(1),

                                TextInput::make('credentials.endpoint')
                                    ->label('Endpoint (URL)')
                                    ->placeholder('https://<account_id>.r2.cloudflarestorage.com')
                                    ->required(fn (Get $get): bool => in_array($get('driver'), ['cloudflare_r2', 's3_compatible'], true))
                                    ->visible(fn (Get $get): bool => in_array($get('driver'), ['cloudflare_r2', 's3_compatible'], true))
                                    ->columnSpan(1),

                                TextInput::make('credentials.bucket')
                                    ->label('Bucket')
                                    ->placeholder('e.g., my-production-backups')
                                    ->required(fn (Get $get): bool => in_array($get('driver'), ['s3', 'cloudflare_r2', 's3_compatible'], true))
                                    ->visible(fn (Get $get): bool => in_array($get('driver'), ['s3', 'cloudflare_r2', 's3_compatible'], true))
                                    ->columnSpan(1),

                                TextInput::make('credentials.region')
                                    ->label('Region')
                                    ->default('us-east-1')
                                    ->placeholder('e.g., us-east-1 or ap-southeast-1')
                                    ->required(fn (Get $get): bool => in_array($get('driver'), ['s3', 's3_compatible'], true))
                                    ->visible(fn (Get $get): bool => in_array($get('driver'), ['s3', 's3_compatible'], true))
                                    ->columnSpan(1),

                                // =========================================================
                                // 3. VPS / SFTP FIELDS
                                // Host, Port, Username, Password, Root Directory (Optional)
                                // =========================================================
                                TextInput::make('credentials.host')
                                    ->label('Host')
                                    ->placeholder('e.g., 203.0.113.88 or backup-vps.example.com')
                                    ->required(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->columnSpan(1),

                                TextInput::make('credentials.port')
                                    ->label('Port')
                                    ->numeric()
                                    ->default(22)
                                    ->placeholder('22')
                                    ->required(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->columnSpan(1),

                                TextInput::make('credentials.username')
                                    ->label('Username')
                                    ->placeholder('e.g., root or backupvault')
                                    ->required(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->columnSpan(1),

                                TextInput::make('credentials.password')
                                    ->label('Password')
                                    ->password()
                                    ->revealable()
                                    ->placeholder('Enter SSH / SFTP password')
                                    ->required(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->columnSpan(1),

                                TextInput::make('credentials.root_directory')
                                    ->label('Root Directory (Optional)')
                                    ->placeholder('e.g., /var/backups/sitevaulta (Leave blank for default home directory)')
                                    ->visible(fn (Get $get): bool => $get('driver') === 'sftp')
                                    ->columnSpanFull(),

                                // =========================================================
                                // LIVE CONNECTION CHECK BUTTON
                                // =========================================================
                                Actions::make([
                                    Action::make('checkStorageConnection')
                                        ->label('🔌 Check Connection')
                                        ->icon(Heroicon::OutlinedCheckCircle)
                                        ->color('info')
                                        ->action(function (Get $get): void {
                                            $driver = (string) ($get('driver') ?: 'google_drive');
                                            $credentials = (array) ($get('credentials') ?? []);
                                            $rootPrefix = (string) ($credentials['root_directory'] ?? $credentials['folder_id'] ?? 'sitevaulta-backups');

                                            $res = StorageAdapterManager::testCredentials($driver, $credentials, $rootPrefix);

                                            if ($res['ok']) {
                                                Notification::make()
                                                    ->title('✅ Connection Verified Successfully')
                                                    ->body($res['message'])
                                                    ->success()
                                                    ->send();
                                            } else {
                                                Notification::make()
                                                    ->title('❌ Connection Check Failed')
                                                    ->body($res['message'])
                                                    ->danger()
                                                    ->send();
                                            }
                                        }),
                                ])->columnSpanFull(),
                            ]),

                        // RIGHT COLUMN (5/12): Live Provider Setup & Credential Guide
                        Section::make('📖 Detailed Provider Setup Guide')
                            ->description('Follow the exact steps below for your selected storage provider.')
                            ->columnSpan(5)
                            ->schema([
                                Placeholder::make('provider_setup_guide')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::renderProviderGuide((string) ($get('driver') ?: 'google_drive'))),
                            ]),
                    ]),
            ]);

    }

    protected static function renderProviderGuide(string $driver): HtmlString
    {
        $boxStyle = 'padding:18px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);font-size:13.5px;line-height:1.7;';
        $titleStyle = 'font-size:15.5px;font-weight:800;color:var(--sv-primary);margin-bottom:12px;border-bottom:1px solid var(--sv-border);padding-bottom:8px;';
        $sectionTitleStyle = 'font-size:14px;font-weight:700;color:var(--sv-info);margin-top:12px;margin-bottom:6px;';
        $codeStyle = 'display:block;background:var(--sv-surface);color:var(--sv-code);padding:10px;border-radius:6px;font-family:monospace;font-size:12px;margin:6px 0;overflow-x:auto;border:1px solid var(--sv-border);word-break:break-all;';
        $badgeStyle = 'background:var(--sv-surface);color:var(--sv-highlight);padding:2px 6px;border-radius:4px;font-family:monospace;font-size:12px;border:1px solid var(--sv-border-strong);';

        $currentCallbackUrl = url('/vaultix/auth/google/callback');

        $html = match ($driver) {
            'google_drive' => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>🟢 Google Drive Detailed Setup Guide</div>

                    <div style='{$sectionTitleStyle}'>1. Google Cloud Console</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Open <strong>console.cloud.google.com</strong> &rarr; <strong>APIs &amp; Services &rarr; Library</strong> and ensure <strong>Google Drive API</strong> is still enabled.</li>
                        <li>Go to <strong>APIs &amp; Services &rarr; Credentials &rarr; Create Credentials &rarr; OAuth client ID</strong> (Web application).</li>
                        <li>Add the following <strong>Authorized Redirect URI</strong>:
                            <code style='{$codeStyle}'>https://engconference.diu.edu.bd/vaultix/auth/google/callback</code>
                            <span style='font-size:11.5px;color:var(--sv-muted);'>Current Environment Callback URI: <code>{$currentCallbackUrl}</code></span>
                        </li>
                        <li>Copy your generated <strong>Client ID</strong> and <strong>Client Secret</strong> and paste them into the form on the left.</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>2. Refresh Token</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Enter your <strong>Client ID</strong> and <strong>Client Secret</strong>, then click the <span style='{$badgeStyle}'>Update Token</span> button inside the <strong>Refresh Token</strong> field — the Refresh Token will automatically populate in the field.</li>
                        <li><strong>If backups fail, click Update Token.</strong></li>
                        <li><strong>Make sure you use the same Client ID/Secret</strong> that was configured in your Google Cloud Console project.</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>3. Folder Name or Folder ID &amp; Verification</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:0;'>
                        <li>Enter a target <strong>Folder Name</strong> (e.g., <span style='{$badgeStyle}'>sitevaulta-backups</span>) or paste your Google Drive <strong>Folder ID</strong> from the Drive folder URL.</li>
                        <li>Click <strong>🔌 Check Connection</strong> to verify your Google Drive setup.</li>
                    </ul>
                </div>
            ",

            's3' => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>🟡 AWS S3 Detailed Setup Guide</div>

                    <div style='{$sectionTitleStyle}'>1. IAM User &amp; Permissions</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Open <strong>AWS Console &rarr; IAM &rarr; Users</strong> and verify IAM User permissions (<span style='{$badgeStyle}'>AmazonS3FullAccess</span>).</li>
                        <li>Go to the user's <strong>Security credentials</strong> tab &rarr; <strong>Create access key</strong>.</li>
                        <li>Copy the <strong>Access Key</strong> and <strong>Secret Key</strong> and paste them into the fields on the left.</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>2. Bucket &amp; Region Configuration</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Ensure the <strong>Bucket Name</strong> and <strong>Region</strong> are correct and match your S3 bucket settings.</li>
                        <li><strong>Region example:</strong> <span style='{$badgeStyle}'>us-east-1</span> (or <span style='{$badgeStyle}'>ap-southeast-1</span>, <span style='{$badgeStyle}'>us-west-2</span>, <span style='{$badgeStyle}'>eu-central-1</span>).</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>3. Connection Check</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:0;'>
                        <li>After filling in <strong>Access Key</strong>, <strong>Secret Key</strong>, <strong>Bucket</strong>, and <strong>Region</strong>, click <strong>🔌 Check Connection</strong> to test read/write access.</li>
                    </ul>
                </div>
            ",

            'cloudflare_r2' => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>🟠 Cloudflare R2 Detailed Setup Guide</div>

                    <div style='{$sectionTitleStyle}'>1. R2 Bucket &amp; Endpoint (URL)</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Open <strong>Cloudflare Dashboard &rarr; R2 Object Storage</strong> and create or select your <strong>Bucket</strong>.</li>
                        <li>Copy the S3 API <strong>Endpoint (URL)</strong> from bucket settings:
                            <code style='{$codeStyle}'>https://&lt;ACCOUNT_ID&gt;.r2.cloudflarestorage.com</code>
                        </li>
                        <li>Paste the <strong>Endpoint (URL)</strong> and <strong>Bucket</strong> name into the form on the left.</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>2. Access Key &amp; Secret Key</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Click <strong>Manage R2 API Tokens &rarr; Create API token</strong>.</li>
                        <li>Grant <strong>Object Read &amp; Write</strong> permissions for your bucket.</li>
                        <li>Copy the generated <strong>Access Key</strong> and <strong>Secret Key</strong> into the fields on the left.</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>3. Connection Check</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:0;'>
                        <li>Click <strong>🔌 Check Connection</strong> to verify your Cloudflare R2 bucket connection.</li>
                    </ul>
                </div>
            ",

            's3_compatible' => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>🔵 S3-Compatible Detailed Setup Guide</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;'>
                        <li>Create a Bucket/Space in your provider dashboard (DigitalOcean Spaces, Wasabi, or Backblaze B2).</li>
                        <li>Enter your provider's <strong>Endpoint (URL)</strong>:
                            <code style='{$codeStyle}'>DigitalOcean: https://sgp1.digitaloceanspaces.com
Wasabi: https://s3.ap-southeast-1.wasabisys.com
Backblaze B2: https://s3.us-west-004.backblazeb2.com</code>
                        </li>
                        <li>Enter your <strong>Access Key</strong>, <strong>Secret Key</strong>, <strong>Bucket</strong>, and <strong>Region</strong>, then click <strong>🔌 Check Connection</strong>.</li>
                    </ul>
                </div>
            ",

            default => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>🟣 VPS / SFTP Detailed Setup Guide</div>

                    <div style='{$sectionTitleStyle}'>1. VPS Host &amp; Port</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Enter your remote VPS <strong>Host</strong> (Public IP address or domain name).</li>
                        <li>Enter the SSH/SFTP <strong>Port</strong> (default is <span style='{$badgeStyle}'>22</span>).</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>2. Username, Password &amp; Root Directory</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:12px;'>
                        <li>Provide the Linux <strong>Username</strong> and <strong>Password</strong> with write access on the VPS.</li>
                        <li><strong>Root Directory (Optional):</strong> Specify a target folder path (e.g., <span style='{$badgeStyle}'>/var/backups/sitevaulta</span> or <span style='{$badgeStyle}'>/home/ubuntu/backups</span>). Leave blank to store backups inside the user's default home folder.</li>
                    </ul>

                    <div style='{$sectionTitleStyle}'>3. Connection Check</div>
                    <ul style='padding-left:18px;color:var(--sv-text-soft);margin-top:4px;margin-bottom:0;'>
                        <li>Click <strong>🔌 Check Connection</strong> to test SSH/SFTP login and directory write permission.</li>
                    </ul>
                </div>
            ",
        };

        return new HtmlString($html);
    }
}
