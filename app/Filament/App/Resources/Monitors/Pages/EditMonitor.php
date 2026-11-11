<?php

namespace App\Filament\App\Resources\Monitors\Pages;

use App\Filament\App\Resources\Monitors\MonitorResource;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Monitoring\MonitorChildChecksSync;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * @property Monitor $record
 */
class EditMonitor extends EditRecord
{
    protected static string $resource = MonitorResource::class;

    /**
     * Extra checks (SSL, Domain, Ping, DNS) are configured on their main monitor, never on their own.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        if ($this->record->parent_id !== null) {
            Notification::make()
                ->title("{$this->record->type->label()} is an extra check of {$this->record->parent->name}")
                ->body('Change it under "Extra checks for this target" on this page.')
                ->info()
                ->send();

            $this->redirect(MonitorResource::getUrl('edit', ['record' => $this->record->parent_id]));
        }
    }

    /**
     * The type is fixed after creation; undo any client-side attempt to change it before it can
     * affect which fields the form shows or validates.
     */
    public function updatedDataType(): void
    {
        $this->data['type'] = $this->record->type->value;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, ...app(MonitorChildChecksSync::class)->formStateFor($this->record)];
    }

    protected function beforeSave(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        $newChecks = app(MonitorChildChecksSync::class)->newChildCount($this->record, (array) ($this->data['extra_checks'] ?? []));

        if ($newChecks > 0 && EntitlementService::remainingMonitorSlots($workspace) < $newChecks) {
            Notification::make()
                ->title('Monitor Quota Reached or Subscription Expired')
                ->body("Adding {$newChecks} extra check(s) needs {$newChecks} free monitor slot(s). Upgrade your plan in Billing to add more.")
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $extraChecks = (array) ($data['extra_checks'] ?? []);
        $extraSettings = (array) ($data['extras'] ?? []);
        unset($data['extra_checks'], $data['extras']);

        return DB::transaction(function () use ($record, $data, $extraChecks, $extraSettings): Model {
            $record = parent::handleRecordUpdate($record, $data);

            /** @var Monitor $record */
            app(MonitorChildChecksSync::class)->sync($record, $extraChecks, $extraSettings);

            return $record;
        });
    }

    protected function afterSave(): void
    {
        MonitorResource::notifyPingAdvice($this->getRecord());
        MonitorResource::auditPortChecks($this->getRecord(), 'updated');
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
