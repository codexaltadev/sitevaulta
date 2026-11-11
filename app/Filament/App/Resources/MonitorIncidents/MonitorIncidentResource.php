<?php

namespace App\Filament\App\Resources\MonitorIncidents;

use App\Enums\MonitorIncidentStatus;
use App\Filament\App\Resources\MonitorIncidents\Pages\ListMonitorIncidents;
use App\Filament\App\Resources\MonitorIncidents\Pages\ViewMonitorIncident;
use App\Filament\App\Resources\MonitorIncidents\Tables\MonitorIncidentsTable;
use App\Models\MonitorIncident;
use App\Models\Workspace;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MonitorIncidentResource extends Resource
{
    protected static ?string $model = MonitorIncident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static string|UnitEnum|null $navigationGroup = 'Uptime Monitoring';

    protected static ?string $navigationLabel = 'Incidents';

    protected static ?string $modelLabel = 'incident';

    protected static ?string $slug = 'incidents';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $workspace = Filament::getTenant();

        if (! $workspace instanceof Workspace) {
            return null;
        }

        $ongoingCount = $workspace->monitorIncidents()->where('status', MonitorIncidentStatus::Ongoing)->count();

        return $ongoingCount > 0 ? (string) $ongoingCount : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return MonitorIncidentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonitorIncidents::route('/'),
            'view' => ViewMonitorIncident::route('/{record}'),
        ];
    }
}
