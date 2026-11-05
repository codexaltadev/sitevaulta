<?php

namespace App\Filament\App\Resources;

use App\Enums\NotificationChannelType;
use App\Filament\App\Resources\NotificationChannelResource\Pages;
use App\Models\NotificationChannel;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;
use UnitEnum;

class NotificationChannelResource extends Resource
{
    protected static ?string $model = NotificationChannel::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static string | UnitEnum | null $navigationGroup = 'Alerts & Agency Reports';

    protected static ?string $navigationLabel = 'Alert Channels';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        // LEFT COLUMN (7/12): Channel Form
                        Section::make('Multi-Channel Alert Configuration')
                            ->description('Receive real-time alerts via Email, Telegram Bot, Slack, Discord, or Custom Webhook.')
                            ->columnSpan(7)
                            ->columns(2)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Channel Label')
                                    ->placeholder('e.g., DevOps Telegram Group / Slack #ops-alerts')
                                    ->required()
                                    ->maxLength(120),

                                Select::make('type')
                                    ->label('Channel Type')
                                    ->options(NotificationChannelType::options())
                                    ->default(NotificationChannelType::Telegram->value)
                                    ->live()
                                    ->required(),

                                // Email Config
                                TextInput::make('config.email')
                                    ->label('Recipient Email Address')
                                    ->email()
                                    ->placeholder('alerts@youragency.com')
                                    ->visible(fn (Get $get): bool => $get('type') === 'email')
                                    ->columnSpanFull(),

                                // Telegram Config
                                TextInput::make('config.bot_token')
                                    ->label('Telegram Bot Token (from @BotFather)')
                                    ->password()
                                    ->revealable()
                                    ->placeholder('123456789:ABCdefGHIjklMNOpqrsTUVwxyz')
                                    ->visible(fn (Get $get): bool => $get('type') === 'telegram'),

                                TextInput::make('config.chat_id')
                                    ->label('Telegram Chat / Channel ID')
                                    ->placeholder('-1001234567890 or 987654321')
                                    ->visible(fn (Get $get): bool => $get('type') === 'telegram'),

                                // Slack / Discord / Custom Webhook Config
                                TextInput::make('config.webhook_url')
                                    ->label('Incoming Webhook URL')
                                    ->url()
                                    ->placeholder('https://hooks.slack.com/services/... or https://discord.com/api/webhooks/...')
                                    ->visible(fn (Get $get): bool => in_array($get('type'), ['slack', 'discord', 'webhook'], true))
                                    ->columnSpanFull(),

                                TextInput::make('config.secret_header')
                                    ->label('Optional Webhook Signing Secret')
                                    ->password()
                                    ->revealable()
                                    ->visible(fn (Get $get): bool => $get('type') === 'webhook')
                                    ->columnSpanFull(),

                                CheckboxList::make('events')
                                    ->label('Subscribed Events')
                                    ->options([
                                        'backup_succeeded' => 'Backup Completed & Verified',
                                        'backup_failed' => 'Backup Failed',
                                        'anomaly_detected' => 'Backup Anomaly Detected (Size Drop / Spike)',
                                        'storage_low_space' => 'Storage Destination Low Free Space',
                                    ])
                                    ->default(['backup_failed', 'anomaly_detected', 'storage_low_space'])
                                    ->columns(2)
                                    ->columnSpanFull(),

                                Toggle::make('is_active')
                                    ->label('Channel Active')
                                    ->default(true),
                            ]),

                        // RIGHT COLUMN (5/12): Step-by-Step Channel Setup Guide
                        Section::make('📖 Step-by-Step Alert Setup Guide')
                            ->description('How to obtain your Bot Token or Webhook URL.')
                            ->columnSpan(5)
                            ->schema([
                                Placeholder::make('channel_setup_guide')
                                    ->hiddenLabel()
                                    ->content(fn (Get $get): HtmlString => self::renderChannelGuide((string) ($get('type') ?: 'telegram'))),
                            ]),
                    ]),
            ]);
    }

    protected static function renderChannelGuide(string $type): HtmlString
    {
        $boxStyle = 'padding:16px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);font-size:13.5px;line-height:1.7;';
        $titleStyle = 'font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:10px;border-bottom:1px solid var(--sv-border);padding-bottom:8px;';

        $html = match ($type) {
            'telegram' => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>✈️ How to Connect Telegram Bot Alerts</div>
                    <ol style='padding-left:18px;color:var(--sv-text-soft);'>
                        <li>Open Telegram and message <strong>@BotFather</strong>.</li>
                        <li>Send <code>/newbot</code>, follow the prompts, and copy the HTTP API <strong>Bot Token</strong>.</li>
                        <li>Start a chat with your new bot (or add it to your DevOps Telegram Group).</li>
                        <li>To find your <strong>Chat ID</strong>, message <strong>@userinfobot</strong> or open:<br><code>https://api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</code></li>
                    </ol>
                </div>
            ",
            'slack' => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>💬 How to Connect Slack Webhook Alerts</div>
                    <ol style='padding-left:18px;color:var(--sv-text-soft);'>
                        <li>Go to <strong>api.slack.com/apps</strong> &rarr; Create New App.</li>
                        <li>Enable <strong>Incoming Webhooks</strong> and click <strong>Add New Webhook to Workspace</strong>.</li>
                        <li>Select your alert channel (e.g., <code>#devops-backups</code>) and copy the Webhook URL.</li>
                    </ol>
                </div>
            ",
            'discord' => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>🎮 How to Connect Discord Channel Alerts</div>
                    <ol style='padding-left:18px;color:var(--sv-text-soft);'>
                        <li>In Discord, open <strong>Channel Settings &rarr; Integrations &rarr; Webhooks</strong>.</li>
                        <li>Click <strong>New Webhook</strong>, choose the channel, and click <strong>Copy Webhook URL</strong>.</li>
                    </ol>
                </div>
            ",
            default => "
                <div style='{$boxStyle}'>
                    <div style='{$titleStyle}'>🔔 Email &amp; Custom Webhook Alerts</div>
                    <p style='color:var(--sv-text-dim);'>SiteVaulta dispatches real-time JSON payloads or formatted HTML emails whenever a subscribed backup event occurs.</p>
                </div>
            ",
        };

        return new HtmlString($html);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('sendTestAlert')
                    ->label('Send Test Alert')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('info')
                    ->action(function (NotificationChannel $record): void {
                        Notification::make()
                            ->title("Test alert dispatched to [{$record->name}] ({$record->type->label()}).")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotificationChannels::route('/'),
            'create' => Pages\CreateNotificationChannel::route('/create'),
            'edit' => Pages\EditNotificationChannel::route('/{record}/edit'),
        ];
    }
}
