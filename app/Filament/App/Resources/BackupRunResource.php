<?php

namespace App\Filament\App\Resources;

use App\Enums\BackupRunStatus;
use App\Enums\DownloadMode;
use App\Filament\App\Resources\BackupRunResource\Pages;
use App\Models\BackupRun;
use App\Services\Backup\ArchivePackagerService;
use App\Services\Restore\DownloadTokenService;
use App\Services\Restore\RestoreGuideGenerator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use UnitEnum;

class BackupRunResource extends Resource
{
    protected static ?string $model = BackupRun::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedArchiveBoxArrowDown;

    protected static string | UnitEnum | null $navigationGroup = 'Backup Management';

    protected static ?string $navigationLabel = '6. Backup Runs & Restore';

    protected static ?int $navigationSort = 6;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Run #')
                    ->sortable(),
                TextColumn::make('trigger_type')
                    ->label('Trigger Mode')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'manual' => '⚡ Manual Run',
                        'scheduled' => '⏰ Scheduled (Auto)',
                        default => ucfirst($state ?? 'Auto'),
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'manual' => 'warning',
                        default => 'success',
                    })
                    ->sortable(),
                TextColumn::make('website.domain')
                    ->label('Website')
                    ->searchable(),
                TextColumn::make('backupPolicy.name')
                    ->label('Policy'),
                TextColumn::make('backup_type')
                    ->label('Chain Type')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'full' ? 'primary' : 'info'),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (BackupRunStatus $state): string => $state->color()),
                TextColumn::make('archive_size_bytes')
                    ->label('Archive Size')
                    ->formatStateUsing(fn (?int $state, BackupRun $record): string => $record->formattedSize()),
                IconColumn::make('is_anomaly')
                    ->label('Anomaly')
                    ->boolean()
                    ->trueColor('danger')
                    ->falseColor('success'),
                TextColumn::make('completed_at')
                    ->label('Completed At')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('secureDownload')
                    ->label('Secure Download')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->visible(fn (BackupRun $record): bool => $record->status === BackupRunStatus::Completed && $record->artifacts()->exists())
                    ->modalHeading(fn (BackupRun $record): string => $record->artifacts()->first()?->is_encrypted
                        ? 'Download Encrypted Backup Archive'
                        : 'Generate Secure Download Link')
                    ->modalDescription(fn (BackupRun $record): ?string => $record->artifacts()->first()?->is_encrypted
                        ? 'This archive was encrypted with your policy passphrase. Choose your preferred download format below:'
                        : 'Click to generate an authenticated, expiring download link for this backup.')
                    ->modalSubmitActionLabel('Generate Download Link')
                    ->form(fn (BackupRun $record): array => $record->artifacts()->first()?->is_encrypted ? [
                        Radio::make('download_option')
                            ->label('Select Download Format')
                            ->options([
                                'decrypted' => '🔓 Decrypt on Download (Download 100% unlocked .zip without any password)',
                                'reencrypt_custom' => '🔑 Re-encrypt with Client/Custom Password (Set a separate password for clients/developers — protects your Master Passphrase)',
                                'encrypted' => '🔒 Download Raw Vault Archive (Keep exact encrypted archive as stored in cloud)',
                            ])
                            ->default('decrypted')
                            ->live(),

                        TextInput::make('master_passphrase')
                            ->label('Master Policy Passphrase')
                            ->password()
                            ->revealable()
                            ->placeholder('Enter your master policy passphrase')
                            ->helperText('Required to unlock the archive before preparing your download.')
                            ->visible(fn (Get $get): bool => in_array($get('download_option'), ['decrypted', 'reencrypt_custom'], true))
                            ->required(fn (Get $get): bool => in_array($get('download_option'), ['decrypted', 'reencrypt_custom'], true)),

                        TextInput::make('custom_client_password')
                            ->label('New Client / Custom Password')
                            ->password()
                            ->revealable()
                            ->placeholder('e.g., ClientPass#2026')
                            ->helperText('The recipient will be prompted for THIS password when opening or extracting files in Windows Explorer, WinRAR, WinZip, or 7-Zip. Your Master Passphrase is never shared!')
                            ->visible(fn (Get $get): bool => $get('download_option') === 'reencrypt_custom')
                            ->required(fn (Get $get): bool => $get('download_option') === 'reencrypt_custom'),
                    ] : [])
                    ->action(function (BackupRun $record, array $data): void {
                        $artifact = $record->artifacts()->first();
                        if (! $artifact) {
                            Notification::make()->title('No artifact found for this backup run.')->danger()->send();
                            return;
                        }

                        $decryptionPassphrase = null;
                        $customClientPassword = null;
                        $downloadOption = $data['download_option'] ?? 'decrypted';

                        if ($artifact->is_encrypted && in_array($downloadOption, ['decrypted', 'reencrypt_custom'], true)) {
                            $masterPass = trim((string) ($data['master_passphrase'] ?? ''));
                            $localPath = Storage::disk('local')->path('vault-mirror/' . ltrim($artifact->remote_path, '/'));

                            if (file_exists($localPath) && ! ArchivePackagerService::verifyPassphrase($localPath, $masterPass)) {
                                Notification::make()
                                    ->title('❌ Invalid Master Passphrase')
                                    ->body('The master passphrase entered does not match this backup archive. Please check your password and try again.')
                                    ->danger()
                                    ->send();
                                return;
                            }

                            $decryptionPassphrase = $masterPass;

                            if ($downloadOption === 'reencrypt_custom') {
                                $customClientPassword = trim((string) ($data['custom_client_password'] ?? ''));
                            }
                        }

                        $mode = $record->backupPolicy?->download_mode ?? DownloadMode::OneTimeToken;
                        $issued = DownloadTokenService::issueDownloadLink($artifact, $mode, $decryptionPassphrase, $customClientPassword);

                        $title = match ($downloadOption) {
                            'reencrypt_custom' => '🔑 Re-encrypted Download Link Ready (Custom Password Set)',
                            'decrypted' => '🔓 Decrypted Download Link Ready (Unprotected)',
                            default => '🔒 Raw Vault Archive Download Link Ready',
                        };

                        $subText = match ($downloadOption) {
                            'reencrypt_custom' => "Archive is protected with your custom password: <strong>" . e($customClientPassword) . "</strong>. When opening or extracting files in Windows Explorer, WinRAR, or 7-Zip, this password will be required. Your master passphrase is safe!",
                            'decrypted' => 'Your archive will download already unlocked as a standard, unencrypted .zip file.',
                            default => 'This download is the exact raw encrypted archive as stored in your cloud vault.',
                        };

                        Notification::make()
                            ->title($title)
                            ->body(new HtmlString(
                                "<p style='margin:0 0 8px 0;font-size:13px;color:var(--sv-text-soft);'>{$subText}</p>" .
                                "Expires at: <strong>{$issued['expires_at']}</strong><br>" .
                                "<a href='" . e($issued['url']) . "' target='_blank' style='color:var(--sv-primary-strong);font-weight:bold;text-decoration:underline;'>Click Here to Download Backup Archive</a>"
                            ))
                            ->persistent()
                            ->success()
                            ->send();
                    }),

                Action::make('viewRestoreGuide')
                    ->label('Restore Guide')
                    ->icon(Heroicon::OutlinedBookOpen)
                    ->color('info')
                    ->modalHeading(fn (BackupRun $record): string => "Step-by-Step Restore Guide — {$record->website?->domain} (Run #{$record->id})")
                    ->modalWidth('4xl')
                    ->modalSubmitAction(false)
                    ->modalContent(function (BackupRun $record): HtmlString {
                        $guide = RestoreGuideGenerator::generateForRun($record);
                        $stepsHtml = '';
                        foreach ($guide['chain_steps'] as $step) {
                            $stepsHtml .= "<li><strong>Step {$step['order']} ({$step['backup_type']}):</strong> <code>" . e($step['file_name']) . "</code> ({$step['size_formatted']}) — SHA-256: <code>" . e($step['sha256_checksum']) . "</code></li>";
                        }

                        $checklistHtml = '';
                        foreach ($guide['checklist'] as $item) {
                            $checklistHtml .= "<li>" . e($item) . "</li>";
                        }

                        return new HtmlString(
                            "<div style='padding:18px;border-radius:10px;background:var(--sv-bg);color:var(--sv-text);border:1px solid var(--sv-border);line-height:1.75;font-size:13.5px;'>" .
                            "<h4 style='color:var(--sv-primary);font-size:15px;font-weight:800;margin-bottom:8px;'>1. Required Recovery Chain Order</h4>" .
                            "<ul style='padding-left:18px;color:var(--sv-text-soft);margin-bottom:16px;'>{$stepsHtml}</ul>" .
                            "<h4 style='color:var(--sv-primary);font-size:15px;font-weight:800;margin-bottom:8px;'>2. Verification &amp; Extraction Commands</h4>" .
                            "<pre style='background:var(--sv-surface);color:var(--sv-code);padding:12px;border-radius:8px;border:1px solid var(--sv-border);overflow-x:auto;font-family:monospace;font-size:12.5px;margin-bottom:16px;'>" .
                            e($guide['commands']['linux_verify_checksum']) . "\n" .
                            e($guide['commands']['decrypt_note']) . "\n" .
                            e($guide['commands']['extract_archive']) . "\n" .
                            e($guide['commands']['restore_files']) . "\n" .
                            e($guide['commands']['restore_database']) .
                            "</pre>" .
                            "<h4 style='color:var(--sv-primary);font-size:15px;font-weight:800;margin-bottom:8px;'>3. Safety Checklist</h4>" .
                            "<ul style='padding-left:18px;color:var(--sv-text-dim);'>{$checklistHtml}</ul>" .
                            "</div>"
                        );
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBackupRuns::route('/'),
        ];
    }
}
