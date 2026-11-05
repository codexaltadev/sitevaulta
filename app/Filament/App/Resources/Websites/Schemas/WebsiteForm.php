<?php

namespace App\Filament\App\Resources\Websites\Schemas;

use App\Models\Website;
use App\Services\Verification\DomainVerificationService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
                                                "<div style='{$boxStyle}'>".
                                                "<div style='font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:8px;'>🔒 Mandatory Domain Ownership Gate</div>".
                                                "<p style='color:var(--sv-text-dim);margin-bottom:10px;'>After saving this website, SiteVaulta will generate a unique cryptographic verification token. You can verify ownership using any 1 of these 3 methods:</p>".
                                                "<ol style='padding-left:18px;color:var(--sv-text-soft);'>".
                                                '<li><strong>DNS TXT Record (Cloudflare / Route53 / Namecheap):</strong> Add a TXT record for <code>_sitevaulta-verify.yourdomain.com</code>.</li>'.
                                                '<li><strong>HTML Verification File:</strong> Upload a single text file to <code>/.well-known/sitevaulta-verify.txt</code>.</li>'.
                                                '<li><strong>Homepage Meta Tag:</strong> Add the <code>&lt;meta name="sitevaulta-verification"&gt;</code> tag inside your homepage <code>&lt;head&gt;</code>.</li>'.
                                                '</ol></div>'
                                            );
                                        }

                                        $challenge = DomainVerificationService::ensureChallengeExists($record);

                                        return new HtmlString(sprintf(
                                            '<div style="%s">'
                                            .'<div style="font-size:15px;font-weight:800;color:var(--sv-primary);margin-bottom:10px;">Choose Any 1 Method Below to Verify</div>'
                                            .'<div style="margin-bottom:10px;"><strong>Method A (DNS TXT Record):</strong><code style="%s">Host: %s<br>Value: %s</code></div>'
                                            .'<div style="margin-bottom:10px;"><strong>Method B (HTML File):</strong><code style="%s">URL: %s%s<br>Content: %s</code></div>'
                                            .'<div><strong>Method C (Homepage Meta Tag):</strong><code style="%s">&lt;meta name="%s" content="%s"&gt;</code></div>'
                                            .'</div>',
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
}
