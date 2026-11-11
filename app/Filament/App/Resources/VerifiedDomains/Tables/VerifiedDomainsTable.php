<?php

namespace App\Filament\App\Resources\VerifiedDomains\Tables;

use App\Filament\App\Resources\Websites\WebsiteResource;
use App\Models\VerifiedDomain;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Security\AuditLogService;
use App\Services\Verification\OwnershipChallengeChecker;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class VerifiedDomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('website:id,name'))
            ->emptyStateHeading('No verified domains yet')
            ->emptyStateDescription('Verify a domain you own to run port checks on it. Verified backup websites and connected servers count automatically.')
            ->columns([
                TextColumn::make('domain')
                    ->weight('semibold')
                    ->searchable(),
                TextColumn::make('verified_at')
                    ->label('Status')
                    ->badge()
                    ->state(fn (VerifiedDomain $record): string => match (true) {
                        $record->isInGracePeriod() => 'Token missing',
                        $record->isVerified() => 'Verified',
                        $record->ownership_lapsed_at !== null => 'Lapsed',
                        default => 'Pending',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Verified' => 'success',
                        'Lapsed' => 'danger',
                        default => 'warning',
                    })
                    // A short line here; the per-method details are in the tooltip and the "How to verify" modal.
                    ->description(fn (VerifiedDomain $record): ?string => match (true) {
                        $record->isInGracePeriod() => 'Put it back by '.$record->graceEndsAt()?->format('M j').' ('.$record->graceDaysLeft().' '.str('day')->plural((int) $record->graceDaysLeft()).' left) or '.($record->isFromBackupWebsite() ? 'backups' : 'port checks').' pause',
                        $record->isVerified() => 'via '.$record->methodLabel().', '.$record->verified_at->diffForHumans().' · re-checked daily',
                        $record->ownership_lapsed_at !== null => ($record->isFromBackupWebsite() ? 'Backups' : 'Port checks').' paused '.$record->ownership_lapsed_at->diffForHumans().' — put the token back and click "Verify now"',
                        filled($record->last_error) => 'Token not found yet — hover or open "How to verify"',
                        default => 'Add the token, then click "Verify now"',
                    })
                    ->tooltip(fn (VerifiedDomain $record): ?string => (! $record->isVerified() || $record->isInGracePeriod()) && filled($record->last_error)
                        ? collect($record->errorLines())->map(fn (string $line): string => "✗ {$line}")->implode("\n")
                        : null),
                TextColumn::make('source')
                    ->label('Source')
                    ->badge()
                    ->state(fn (VerifiedDomain $record): string => $record->website_id !== null ? 'Backup website' : 'Added here')
                    ->color(fn (string $state): string => $state === 'Backup website' ? 'info' : 'gray')
                    ->description(fn (VerifiedDomain $record): ?string => $record->website?->name)
                    ->url(fn (VerifiedDomain $record): ?string => $record->website_id !== null ? WebsiteResource::getUrl('edit', ['record' => $record->website_id]) : null),
                TextColumn::make('last_attempted_at')
                    ->label('Last attempt')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('instructions')
                    ->label('How to verify')
                    ->icon(Heroicon::OutlinedInformationCircle)
                    ->color('gray')
                    ->hidden(fn (VerifiedDomain $record): bool => $record->isFromBackupWebsite() && $record->isVerified() && ! $record->isInGracePeriod())
                    ->modalHeading(fn (VerifiedDomain $record): string => "Verify {$record->domain}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        TextEntry::make('last_result')
                            ->label(fn (VerifiedDomain $record): string => 'Last attempt '.($record->last_attempted_at?->diffForHumans() ?? '').' — not found with:')
                            ->state(fn (VerifiedDomain $record): HtmlString => $record->errorListHtml())
                            ->color('danger')
                            ->visible(fn (VerifiedDomain $record): bool => (! $record->isVerified() || $record->isInGracePeriod()) && filled($record->last_error)),
                        TextEntry::make('dns')
                            ->label('Option 1 — DNS TXT record (recommended)')
                            ->state(fn (VerifiedDomain $record): string => "Name: {$record->dnsRecordName()}   ·   Type: TXT   ·   Value: {$record->token}")
                            ->copyable()
                            ->copyableState(fn (VerifiedDomain $record): string => $record->token),
                        TextEntry::make('file')
                            ->label('Option 2 — verification file')
                            ->state(fn (VerifiedDomain $record): string => "Upload sitevaulta-verify.txt containing {$record->token} to https://{$record->domain}".OwnershipChallengeChecker::ROOT_FILE_PATH." (web root) or https://{$record->domain}".VerifiedDomain::FILE_PATH)
                            ->copyable()
                            ->copyableState(fn (VerifiedDomain $record): string => $record->token),
                        TextEntry::make('meta')
                            ->label('Option 3 — HTML meta tag in the homepage <head>')
                            ->state(fn (VerifiedDomain $record): string => $record->metaTag())
                            ->helperText('"Verify now" tries all three options.')
                            ->copyable(),
                    ]),
                Action::make('verify')
                    ->label('Verify now')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('success')
                    ->hidden(fn (VerifiedDomain $record): bool => $record->isFromBackupWebsite() && $record->isVerified() && ! $record->isInGracePeriod())
                    ->action(function (VerifiedDomain $record): void {
                        $verified = app(HostOwnershipVerifier::class)->verify($record);

                        AuditLogService::log(
                            event: $verified ? 'monitoring.domain.verified' : 'monitoring.domain.verification_failed',
                            auditable: $record,
                            metadata: ['domain' => $record->domain, 'method' => $record->verification_method],
                            workspaceId: $record->workspace_id,
                        );

                        $notification = Notification::make()->title($verified ? "{$record->domain} is verified" : 'Verification failed');
                        $verified
                            ? $notification->success()->body('You can now run port checks on this domain.')
                            : $notification->danger()->body($record->errorListHtml());
                        $notification->send();
                    }),
                // A backup website owns its domain row; it goes away with the website.
                DeleteAction::make()
                    ->hidden(fn (VerifiedDomain $record): bool => $record->isFromBackupWebsite()),
            ]);
    }
}
