<?php

namespace App\Filament\App\Resources\Websites\Tables;

use App\Enums\VerificationMethod;
use App\Enums\WebsiteVerificationStatus;
use App\Models\Website;
use App\Services\Verification\DomainVerificationService;
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
}
