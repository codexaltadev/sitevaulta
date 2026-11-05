<?php

namespace App\Filament\App\Resources\ServerConnections\Schemas;

use App\Enums\DatabaseDriver;
use App\Enums\FileProtocol;
use App\Services\Settings\SystemSettingsService;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;

class ServerConnectionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        // LEFT COLUMN (7/12): Server & DB Form
                        Grid::make(1)
                            ->columnSpan(7)
                            ->schema([
                                Section::make('Target Verified Website & Connector Name')
                                    ->columns(2)
                                    ->schema([
                                        Select::make('website_id')
                                            ->label('Verified Website')
                                            ->options(fn () => Filament::getTenant()
                                                ->websites()
                                                ->pluck('name', 'id'))
                                            ->required()
                                            ->searchable(),

                                        TextInput::make('name')
                                            ->label('Connection Label')
                                            ->placeholder('e.g., Production VPS SSH + MySQL')
                                            ->required()
                                            ->maxLength(120),
                                    ]),

                                Section::make('File Server Access (100% Framework-Agnostic)')
                                    ->columns(2)
                                    ->schema([
                                        Select::make('file_protocol')
                                            ->label('File Connection Protocol')
                                            ->options(function (): array {
                                                $enabled = (array) SystemSettingsService::get(
                                                    'backup_engine',
                                                    'enabled_file_protocols',
                                                    ['ssh_sftp', 'ftp_ftps', 'cpanel_api', 'vaultix_agent']
                                                );
                                                $all = FileProtocol::options();

                                                return collect($all)
                                                    ->filter(fn ($label, $key) => $key === 'none' || in_array($key, $enabled, true))
                                                    ->all();
                                            })
                                            ->default(FileProtocol::SshSftp->value)
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                                if ($state === 'ftp_ftps') {
                                                    $set('file_credentials.port', 21);
                                                    $set('db_use_ssh_tunnel', false);
                                                } elseif ($state === 'ssh_sftp') {
                                                    $set('file_credentials.port', 22);
                                                } elseif ($state === 'cpanel_api') {
                                                    $set('db_use_ssh_tunnel', false);
                                                    $set('file_credentials.root_path', null);
                                                }
                                            })
                                            ->required()
                                            ->columnSpanFull(),

                                        TextInput::make('file_credentials.host')
                                            ->label('Server Hostname / Public IP')
                                            ->placeholder('e.g., 203.0.113.50 or server.example.com')
                                            ->visible(fn (Get $get): bool => in_array($get('file_protocol'), ['ssh_sftp', 'ftp_ftps'], true)),

                                        TextInput::make('file_credentials.port')
                                            ->label('Port')
                                            ->numeric()
                                            ->default(22)
                                            ->visible(fn (Get $get): bool => in_array($get('file_protocol'), ['ssh_sftp', 'ftp_ftps'], true)),

                                        TextInput::make('file_credentials.username')
                                            ->label('Server / cPanel Username')
                                            ->visible(fn (Get $get): bool => in_array($get('file_protocol'), ['ssh_sftp', 'ftp_ftps', 'cpanel_api'], true)),

                                        Select::make('file_credentials.auth_type')
                                            ->label('SSH Authentication Method')
                                            ->options([
                                                'password' => 'Password',
                                                'private_key' => 'SSH Private Key (Recommended)',
                                            ])
                                            ->default('password')
                                            ->live()
                                            ->visible(fn (Get $get): bool => $get('file_protocol') === 'ssh_sftp'),

                                        TextInput::make('file_credentials.password')
                                            ->label('SSH / FTP Password')
                                            ->password()
                                            ->revealable()
                                            ->visible(fn (Get $get): bool => $get('file_protocol') === 'ftp_ftps'
                                                || ($get('file_protocol') === 'ssh_sftp' && $get('file_credentials.auth_type') !== 'private_key')),

                                        Textarea::make('file_credentials.private_key')
                                            ->label('SSH Private Key (PEM / OpenSSH Format)')
                                            ->rows(4)
                                            ->columnSpanFull()
                                            ->visible(fn (Get $get): bool => $get('file_protocol') === 'ssh_sftp'
                                                && $get('file_credentials.auth_type') === 'private_key'),

                                        TextInput::make('file_credentials.cpanel_url')
                                            ->label('cPanel Host URL (Port 2083)')
                                            ->placeholder('https://server.example.com:2083')
                                            ->helperText('HTTPS only. Port 2083 is used when no port is given.')
                                            ->visible(fn (Get $get): bool => $get('file_protocol') === 'cpanel_api'),

                                        TextInput::make('file_credentials.api_token')
                                            ->label('cPanel UAPI Token')
                                            ->password()
                                            ->revealable()
                                            ->visible(fn (Get $get): bool => $get('file_protocol') === 'cpanel_api'),

                                        TextInput::make('file_credentials.cpanel_password')
                                            ->label('cPanel Account Password (For 100% Read-Only SQL & Binary Stream)')
                                            ->helperText('Required when using cPanel Managed MySQL because cPanel blocks API Tokens on /getsqlbackup.')
                                            ->password()
                                            ->revealable()
                                            ->visible(fn (Get $get): bool => $get('file_protocol') === 'cpanel_api'),

                                        TextInput::make('file_credentials.agent_token')
                                            ->label('Vaultix Agent Secret Token')
                                            ->password()
                                            ->revealable()
                                            ->visible(fn (Get $get): bool => $get('file_protocol') === 'vaultix_agent'),

                                        TextInput::make('file_credentials.root_path')
                                            ->label('Website Root Directory Path on Server')
                                            ->placeholder('/var/www/html or /home/username/public_html')
                                            ->default('/var/www/html')
                                            ->helperText(fn (Get $get): ?string => $get('file_protocol') === 'cpanel_api'
                                                ? 'Leave empty to use /home/{cPanel username}/public_html.'
                                                : null)
                                            ->visible(fn (Get $get): bool => $get('file_protocol') !== 'none')
                                            ->columnSpanFull(),
                                    ]),

                                Section::make('Database Access (Direct or Over SSH Tunnel)')
                                    ->columns(2)
                                    ->schema([
                                        Select::make('db_driver')
                                            ->label('Database Engine')
                                            ->options(DatabaseDriver::options())
                                            ->default(DatabaseDriver::MySql->value)
                                            ->live()
                                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                                if ($state === 'pgsql') {
                                                    $set('db_credentials.port', 5432);
                                                } elseif (in_array($state, ['mysql', 'mariadb'], true)) {
                                                    $set('db_credentials.port', 3306);
                                                }
                                            })
                                            ->required(),

                                        Toggle::make('db_use_ssh_tunnel')
                                            ->label('Connect via SSH Tunnel (Recommended)')
                                            ->default(true)
                                            ->live()
                                            ->visible(fn (Get $get): bool => $get('db_driver') !== 'none' && $get('file_protocol') === 'ssh_sftp'),

                                        TextInput::make('db_credentials.host')
                                            ->label('Database Host')
                                            ->default('127.0.0.1')
                                            ->helperText(fn (Get $get): string => $get('db_use_ssh_tunnel')
                                                ? 'Keep as 127.0.0.1 when connecting through SSH Tunnel.'
                                                : 'Enter remote public DB host (must allow remote connections).')
                                            ->visible(fn (Get $get): bool => ! in_array($get('db_driver'), ['none', 'cpanel_mysql'], true)),

                                        TextInput::make('db_credentials.port')
                                            ->label('Database Port')
                                            ->numeric()
                                            ->default(3306)
                                            ->visible(fn (Get $get): bool => ! in_array($get('db_driver'), ['none', 'cpanel_mysql'], true)),

                                        TextInput::make('db_credentials.database')
                                            ->label('Database Name')
                                            ->placeholder('e.g., shop_production')
                                            ->helperText(fn (Get $get): ?string => $get('db_driver') === 'cpanel_mysql'
                                                ? 'Dumped through the cPanel API token (no Remote MySQL needed). The "username_" prefix is added automatically.'
                                                : null)
                                            ->visible(fn (Get $get): bool => $get('db_driver') !== 'none'),

                                        TextInput::make('db_credentials.username')
                                            ->label('Database Username')
                                            ->visible(fn (Get $get): bool => ! in_array($get('db_driver'), ['none', 'cpanel_mysql'], true)),

                                        TextInput::make('db_credentials.password')
                                            ->label('Database Password')
                                            ->password()
                                            ->revealable()
                                            ->visible(fn (Get $get): bool => ! in_array($get('db_driver'), ['none', 'cpanel_mysql'], true)),
                                    ]),
                            ]),

                        // RIGHT COLUMN (5/12): Live Protocol & Database Setup Guide
                        Section::make('📖 Step-by-Step Server & DB Setup Guide')
                            ->description('Instructions update automatically based on your selected File Protocol and Database Engine.')
                            ->columnSpan(5)
                            ->schema([
                                Placeholder::make('server_connector_guide')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::renderConnectorGuide(
                                        (string) ($get('file_protocol') ?: 'ssh_sftp'),
                                        (string) ($get('db_driver') ?: 'mysql'),
                                        (bool) ($get('db_use_ssh_tunnel') ?? true)
                                    )),
                            ]),
                    ]),
            ]);

    }

    protected static function renderConnectorGuide(string $protocol, string $dbDriver, bool $useSshTunnel): HtmlString
    {
        $boxStyle = 'padding:16px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);font-size:13.5px;line-height:1.7;';
        $titleStyle = 'font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:10px;border-bottom:1px solid var(--sv-border);padding-bottom:8px;';
        $codeStyle = 'display:block;background:var(--sv-surface);color:var(--sv-code);padding:10px;border-radius:6px;font-family:monospace;font-size:12px;margin:8px 0;overflow-x:auto;border:1px solid var(--sv-border);';

        $protocolGuide = match ($protocol) {
            'ssh_sftp' => "
                <div style='{$titleStyle}'>🟢 SSH / SFTP Connection Guide (VPS / Cloud Servers)</div>
                <ol style='padding-left:18px;color:var(--sv-text-soft);margin-bottom:14px;'>
                    <li>Best for DigitalOcean, AWS EC2, Linode, Hetzner, Vultr, or any Linux VPS.</li>
                    <li>Enter your server's <strong>Public IP</strong> and SSH port (default <code>22</code>).</li>
                    <li>If using <strong>SSH Private Key</strong>, generate a key pair and add the public key to your server:<code style='{$codeStyle}'>cat ~/.ssh/id_ed25519.pub >> ~/.ssh/authorized_keys</code></li>
                    <li>Set <strong>Website Root Directory</strong> to your project path (e.g., <code>/var/www/html</code> or <code>/home/forge/example.com</code>).</li>
                </ol>
            ",
            'ftp_ftps' => "
                <div style='{$titleStyle}'>🟠 FTP / FTPS Connection Guide (Shared Hosting)</div>
                <ol style='padding-left:18px;color:var(--sv-text-soft);margin-bottom:14px;'>
                    <li>Log in to your hosting control panel (cPanel / Plesk / DirectAdmin) and open <strong>FTP Accounts</strong>.</li>
                    <li>Create a dedicated FTP account pointed to your website's <code>public_html</code> directory.</li>
                    <li>Enter the FTP hostname, Port (<code>21</code>), FTP username, and password on the left.</li>
                </ol>
            ",
            'cpanel_api' => "
                <div style='{$titleStyle}'>🟡 cPanel UAPI Token Guide (No SSH Needed)</div>
                <ol style='padding-left:18px;color:var(--sv-text-soft);margin-bottom:14px;'>
                    <li>Log in to your <strong>cPanel Dashboard</strong> (<code>https://yourdomain.com:2083</code>).</li>
                    <li>Go to <strong>Security &rarr; Manage API Tokens</strong>.</li>
                    <li>Enter a token name (e.g., <code>sitevaulta</code>) and click <strong>Create</strong>.</li>
                    <li>Copy the generated API Token and paste it into <strong>cPanel UAPI Token</strong> on the left.</li>
                    <li>Host URL: <code>https://yourdomain.com:2083</code>. Leave the root path empty for <code>/home/username/public_html</code>.</li>
                    <li>For the database, choose <strong>cPanel Managed MySQL</strong> and enter only the database name; the dump is taken through the same token.</li>
                </ol>
            ",
            'vaultix_agent' => "
                <div style='{$titleStyle}'>🟣 Standalone Vaultix PHP Agent Guide</div>
                <ol style='padding-left:18px;color:var(--sv-text-soft);margin-bottom:14px;'>
                    <li>Enter a strong random secret in <strong>Vaultix Agent Secret Token</strong> and save this connection.</li>
                    <li>In the connections table, click <strong>[Download Agent PHP]</strong>.</li>
                    <li>Upload the downloaded file to your website root as <code>_vaultix-agent.php</code>.</li>
                </ol>
            ",
            default => "<div style='{$titleStyle}'>File Backup Disabled (Database-Only Mode)</div>",
        };

        $dbGuide = '';
        if ($dbDriver !== 'none') {
            $dbLabel = strtoupper($dbDriver);
            $tunnelNote = ($protocol === 'ssh_sftp' && $useSshTunnel)
                ? 'Because <strong>SSH Tunnel</strong> is enabled, SiteVaulta connects to your SSH server first and runs <code>mysqldump</code> / <code>pg_dump</code> locally against <code>127.0.0.1</code>—you do <strong>NOT</strong> need to expose port 3306/5432 to the public internet!'
                : 'In <strong>Direct DB Mode</strong>, ensure your database server allows remote connections from SiteVaulta and enter the public DB hostname.';

            $dbGuide = "
                <div style='{$titleStyle};margin-top:14px;'>🗄️ {$dbLabel} Database Backup Guide</div>
                <p style='color:var(--sv-text-dim);margin-bottom:8px;'>{$tunnelNote}</p>
                <p style='color:var(--sv-muted);font-size:12.5px;'>Tip: You can find your exact DB name, username, and password inside your website's <code>.env</code> or <code>wp-config.php</code> file.</p>
            ";
        }

        return new HtmlString("<div style='{$boxStyle}'>{$protocolGuide}{$dbGuide}</div>");
    }
}
