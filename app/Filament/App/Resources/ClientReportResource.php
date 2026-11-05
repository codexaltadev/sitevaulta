<?php

namespace App\Filament\App\Resources;

use App\Filament\App\Resources\ClientReportResource\Pages;
use App\Models\ClientReport;
use App\Models\Website;
use BackedEnum;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ClientReportResource extends Resource
{
    protected static ?string $model = ClientReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Alerts & Agency Reports';

    protected static ?string $navigationLabel = 'White-Label Client Reports';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Automated White-Label Client PDF Backup Report')
                    ->description('Send branded weekly or monthly backup health & SHA-256 verification summary reports to agency clients.')
                    ->columns(2)
                    ->schema([
                        Select::make('website_id')
                            ->label('Client Website')
                            ->options(function () {
                                $tenant = Filament::getTenant();

                                return Website::query()
                                    ->where('workspace_id', $tenant?->id)
                                    ->pluck('name', 'id');
                            })
                            ->required(),

                        TextInput::make('recipient_email')
                            ->label('Client Recipient Email')
                            ->email()
                            ->required()
                            ->maxLength(150),

                        Select::make('frequency')
                            ->label('Report Frequency')
                            ->options([
                                'weekly' => 'Weekly Summary',
                                'monthly' => 'Monthly Executive Report',
                            ])
                            ->default('monthly')
                            ->required(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClientReports::route('/'),
            'create' => Pages\CreateClientReport::route('/create'),
            'edit' => Pages\EditClientReport::route('/{record}/edit'),
        ];
    }
}
