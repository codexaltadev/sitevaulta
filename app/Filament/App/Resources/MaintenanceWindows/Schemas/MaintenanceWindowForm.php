<?php

namespace App\Filament\App\Resources\MaintenanceWindows\Schemas;

use App\Enums\MaintenanceScheduleType;
use App\Models\MaintenanceWindow;
use App\Models\Workspace;
use App\Rules\BelongsToWorkspaceMonitors;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MaintenanceWindowForm
{
    /**
     * @var array<int, string>
     */
    public const DURATION_OPTIONS = [
        15 => '15 minutes',
        30 => '30 minutes',
        60 => '1 hour',
        120 => '2 hours',
        180 => '3 hours',
        240 => '4 hours',
        360 => '6 hours',
        480 => '8 hours',
        720 => '12 hours',
        1440 => '24 hours',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Maintenance window')
                    ->description(fn (): string => 'Monitors in this window are not checked, open no incidents and send no alerts. Times use the workspace timezone ('.self::workspaceTimezone().').')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->placeholder('e.g. Weekly server updates')
                            ->required()
                            ->maxLength(150),

                        Select::make('schedule_type')
                            ->label('Repeat')
                            ->options(MaintenanceScheduleType::options())
                            ->default(MaintenanceScheduleType::OneTime->value)
                            ->live()
                            ->required(),

                        DateTimePicker::make('starts_at')
                            ->label('Starts at')
                            ->seconds(false)
                            ->timezone(self::workspaceTimezone())
                            ->required(fn (Get $get): bool => self::selectedType($get) === MaintenanceScheduleType::OneTime)
                            ->visible(fn (Get $get): bool => self::selectedType($get) === MaintenanceScheduleType::OneTime),

                        TimePicker::make('start_time')
                            ->label('Start time')
                            ->seconds(false)
                            ->format('H:i')
                            ->required(fn (Get $get): bool => self::selectedType($get)->isRecurring())
                            ->visible(fn (Get $get): bool => self::selectedType($get)->isRecurring()),

                        CheckboxList::make('days_of_week')
                            ->label('On these days')
                            ->options(MaintenanceWindow::WEEKDAY_LABELS)
                            ->columns(4)
                            ->required(fn (Get $get): bool => self::selectedType($get) === MaintenanceScheduleType::Weekly)
                            ->visible(fn (Get $get): bool => self::selectedType($get) === MaintenanceScheduleType::Weekly)
                            ->columnSpanFull(),

                        Select::make('day_of_month')
                            ->label('Day of the month')
                            ->options(array_combine(range(1, 28), range(1, 28)))
                            ->helperText('Days 29–31 are left out so the window runs every month.')
                            ->required(fn (Get $get): bool => self::selectedType($get) === MaintenanceScheduleType::Monthly)
                            ->visible(fn (Get $get): bool => self::selectedType($get) === MaintenanceScheduleType::Monthly),

                        Select::make('duration_minutes')
                            ->label('Duration')
                            ->options(self::DURATION_OPTIONS)
                            ->default(60)
                            ->required(),

                        Select::make('monitors')
                            ->label('Monitors')
                            ->relationship('monitors', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable()
                            ->required()
                            ->rules(fn (): array => [new BelongsToWorkspaceMonitors(
                                Filament::getTenant() instanceof Workspace ? Filament::getTenant() : null
                            )])
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),
            ]);
    }

    private static function selectedType(Get $get): MaintenanceScheduleType
    {
        $type = $get('schedule_type');

        if ($type instanceof MaintenanceScheduleType) {
            return $type;
        }

        return MaintenanceScheduleType::tryFrom((string) $type) ?? MaintenanceScheduleType::OneTime;
    }

    private static function workspaceTimezone(): string
    {
        $workspace = Filament::getTenant();

        return $workspace instanceof Workspace && $workspace->timezone
            ? $workspace->timezone
            : (string) config('app.timezone');
    }
}
