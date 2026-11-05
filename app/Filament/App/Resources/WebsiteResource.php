<?php

namespace App\Filament\App\Resources;

use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use App\Filament\App\Resources\WebsiteResource\Pages;
use App\Models\Website;
use App\Services\Verification\DomainVerificationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

class WebsiteResource extends Resource
{
    protected static ?string $model = Website::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static string | UnitEnum | null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '1. Websites & Verification';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Grid::make(12)
                    ->columnSpanFull()
                    ->schema([
                        // LEFT COLUMN (7/12): Website Registration Form
                        Section::make('Website Details & Source Size Estimates')
                            ->description('Register your website URL first, then verify domain ownership via DNS TXT, HTML File, or Meta Tag.')
                            ->columnSpan(7)
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
                                    ->maxLength(255),

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
                                                "<div style='{$boxStyle}'>" .
                                                "<div style='font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:8px;'>🔒 Mandatory Domain Ownership Gate</div>" .
                                                "<p style='color:var(--sv-text-dim);margin-bottom:10px;'>After saving this website, SiteVaulta will generate a unique cryptographic verification token. You can verify ownership using any 1 of these 3 methods:</p>" .
                                                "<ol style='padding-left:18px;color:var(--sv-text-soft);'>" .
                                                "<li><strong>DNS TXT Record (Cloudflare / Route53 / Namecheap):</strong> Add a TXT record for <code>_sitevaulta-verify.yourdomain.com</code>.</li>" .
                                                "<li><strong>HTML Verification File:</strong> Upload a single text file to <code>/.well-known/sitevaulta-verify.txt</code>.</li>" .
                                                "<li><strong>Homepage Meta Tag:</strong> Add the <code>&lt;meta name=\"sitevaulta-verification\"&gt;</code> tag inside your homepage <code>&lt;head&gt;</code>.</li>" .
                                                "</ol></div>"
                                            );
                                        }

                                        $challenge = DomainVerificationService::ensureChallengeExists($record);

                                        return new HtmlString(sprintf(
                                            '<div style="%s">'
                                            . '<div style="font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:10px;">Choose Any 1 Method Below to Verify</div>'
                                            . '<div style="margin-bottom:10px;"><strong>Method A (DNS TXT Record):</strong><code style="%s">Host: %s<br>Value: %s</code></div>'
                                            . '<div style="margin-bottom:10px;"><strong>Method B (HTML File):</strong><code style="%s">URL: %s%s<br>Content: %s</code></div>'
                                            . '<div><strong>Method C (Homepage Meta Tag):</strong><code style="%s">&lt;meta name="%s" content="%s"&gt;</code></div>'
                                            . '</div>',
                                            $boxStyle,
                                            $codeStyle,
                                            e($challenge->dns_host),
                                            e($challenge->token),
                                            $codeStyle,
                                            e(rtrim($record->url, '/')),
                                            e($challenge->file_path),
                                            e($challenge->token),
                                            $codeStyle,
                                            e($challenge->meta_tag_name),
                                            e($challenge->token)
                                        ));
                                    }),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
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

                TextColumn::make('verified_at')
                    ->label('Verified At')
                    ->dateTime()
                    ->placeholder('Not verified yet'),
            ])
            ->recordActions([
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
                                    . '<div><strong style="color:var(--sv-primary);">Token:</strong> <code>%s</code></div>'
                                    . '<div><strong style="color:var(--sv-primary);">DNS TXT Host:</strong> <code>%s</code></div>'
                                    . '<div><strong style="color:var(--sv-primary);">File Path:</strong> <code>%s</code></div>'
                                    . '<div><strong style="color:var(--sv-primary);">Meta Tag:</strong> <code>&lt;meta name="%s" content="%s"&gt;</code></div>'
                                    . '</div>',
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
                        $plainToken = 'sv_deploy_' . Str::random(36);
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebsites::route('/'),
            'create' => Pages\CreateWebsite::route('/create'),
            'edit' => Pages\EditWebsite::route('/{record}/edit'),
        ];
    }
}
