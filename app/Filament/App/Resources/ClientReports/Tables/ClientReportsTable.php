<?php

namespace App\Filament\App\Resources\ClientReports\Tables;

use App\Models\ClientReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ClientReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('website.domain')
                    ->label('Website')
                    ->searchable(),
                TextColumn::make('recipient_email')
                    ->label('Recipient Email')
                    ->searchable(),
                TextColumn::make('frequency')
                    ->badge(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('last_sent_at')
                    ->label('Last Dispatched')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('downloadPdfReport')
                    ->label('Download PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->action(function (ClientReport $record) {
                        $workspace = $record->workspace;
                        $whitelabel = $workspace?->whitelabel_config ?? [];
                        $brandName = (string) ($whitelabel['company_name'] ?? $workspace?->name ?? 'SiteVaulta Agency');
                        $logoUrl = (string) ($whitelabel['logo_url'] ?? '');
                        $brandColor = (string) ($whitelabel['brand_color'] ?? '#10b981');
                        $supportEmail = (string) ($whitelabel['support_email'] ?? $workspace?->owner?->email ?? 'support@sitevaulta.com');
                        $footer = (string) ($whitelabel['report_footer'] ?? 'Automated Website Backup Verification Report');
                        $website = $record->website;
                        $runs = $website
                            ? $website->backupRuns()->with('artifacts')->where('status', 'completed')->latest('id')->limit(15)->get()
                            : collect();

                        $pdf = Pdf::loadView('reports.client-backup-report-pdf', [
                            'report' => $record,
                            'workspace' => $workspace,
                            'website' => $website,
                            'runs' => $runs,
                            'brandName' => $brandName,
                            'logoUrl' => $logoUrl,
                            'brandColor' => $brandColor,
                            'supportEmail' => $supportEmail,
                            'footer' => $footer,
                        ])->setPaper('a4', 'portrait');

                        return response()->streamDownload(
                            fn () => print ($pdf->output()),
                            'backup-report-'.($website?->domain ?? 'client').'.pdf'
                        );
                    }),
                Action::make('sendNow')
                    ->label('Send Report Now')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('info')
                    ->action(function (ClientReport $record): void {
                        $record->update(['last_sent_at' => now()]);
                        Notification::make()
                            ->title("White-label backup summary dispatched to {$record->recipient_email}.")
                            ->success()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
