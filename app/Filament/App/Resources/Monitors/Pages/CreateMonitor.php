<?php

namespace App\Filament\App\Resources\Monitors\Pages;

use App\Filament\App\Resources\Monitors\MonitorResource;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Monitoring\MonitorChildChecksSync;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CreateMonitor extends CreateRecord
{
    protected static string $resource = MonitorResource::class;

    protected function beforeCreate(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $slotsNeeded = 1 + count((array) ($this->data['extra_checks'] ?? []));

        if (! EntitlementService::canAddMonitor($workspace) || EntitlementService::remainingMonitorSlots($workspace) < $slotsNeeded) {
            Notification::make()
                ->title('Monitor Quota Reached or Subscription Expired')
                ->body('This needs '.$slotsNeeded.' monitor slot(s) (1 + extra checks). Your current plan allows '.EntitlementService::monitorLimit($workspace).' monitors. Upgrade your plan in Billing to add more.')
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $extraChecks = (array) ($data['extra_checks'] ?? []);
        $extraSettings = (array) ($data['extras'] ?? []);
        unset($data['extra_checks'], $data['extras']);

        return DB::transaction(function () use ($data, $extraChecks, $extraSettings): Monitor {
            /** @var Monitor $monitor */
            $monitor = parent::handleRecordCreation($data);

            app(MonitorChildChecksSync::class)->sync($monitor, $extraChecks, $extraSettings);

            return $monitor;
        });
    }

    protected function afterCreate(): void
    {
        MonitorResource::notifyPingAdvice($this->getRecord());
        MonitorResource::auditPortChecks($this->getRecord(), 'created');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
