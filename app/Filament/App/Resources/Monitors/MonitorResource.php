<?php

namespace App\Filament\App\Resources\Monitors;

use App\Enums\MonitorType;
use App\Filament\App\Resources\Monitors\Pages\CreateMonitor;
use App\Filament\App\Resources\Monitors\Pages\EditMonitor;
use App\Filament\App\Resources\Monitors\Pages\ListMonitors;
use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;
use App\Filament\App\Resources\Monitors\Schemas\MonitorForm;
use App\Models\Monitor;
use App\Services\Monitoring\MonitorSetupAdvisor;
use App\Services\Security\AuditLogService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

class MonitorResource extends Resource
{
    protected static ?string $model = Monitor::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static string|UnitEnum|null $navigationGroup = 'Uptime Monitoring';

    protected static ?string $navigationLabel = 'Monitors';

    protected static ?int $navigationSort = 0;

    public static function form(Schema $schema): Schema
    {
        return MonitorForm::configure($schema);
    }

    /**
     * After saving, surface setup problems right away (server blocks ping, DNS record type not present).
     */
    public static function notifyPingAdvice(Monitor $monitor): void
    {
        foreach (app(MonitorSetupAdvisor::class)->adviseFor($monitor) as $advice) {
            $notification = Notification::make()->title($advice['title'])->body($advice['body'])->persistent();
            $advice['level'] === 'danger' ? $notification->danger() : $notification->warning();
            $notification->send();
        }
    }

    /**
     * Port checks are scans, so every create/change is recorded in the workspace audit log.
     */
    public static function auditPortChecks(Monitor $monitor, string $action): void
    {
        $portChecks = collect([$monitor])
            ->merge($monitor->children()->get())
            ->filter(fn (Monitor $candidate): bool => $candidate->type === MonitorType::Port);

        foreach ($portChecks as $portCheck) {
            AuditLogService::log(
                event: "monitoring.port_check.{$action}",
                auditable: $portCheck,
                metadata: ['host' => $portCheck->host, 'ports' => $portCheck->portList(), 'interval_seconds' => $portCheck->interval_seconds],
                workspaceId: $portCheck->workspace_id,
            );
        }
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMonitors::route('/'),
            'create' => CreateMonitor::route('/create'),
            'view' => ViewMonitor::route('/{record}'),
            'edit' => EditMonitor::route('/{record}/edit'),
        ];
    }
}
