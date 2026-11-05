<?php

namespace App\Filament\App\Resources\ClientReports\Schemas;

use App\Models\Website;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ClientReportForm
{
    public static function configure(Schema $schema): Schema
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
}
