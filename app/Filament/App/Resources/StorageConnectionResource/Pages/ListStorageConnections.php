<?php

namespace App\Filament\App\Resources\StorageConnectionResource\Pages;

use App\Filament\App\Resources\StorageConnectionResource;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListStorageConnections extends ListRecords
{
    protected static string $resource = StorageConnectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->before(function (CreateAction $action): void {
                    /** @var Workspace $workspace */
                    $workspace = Filament::getTenant();
                    if (! EntitlementService::canAddStorageConnection($workspace)) {
                        Notification::make()
                            ->title('Storage Connection Limit Reached')
                            ->body('Upgrade your plan to connect additional storage providers.')
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}

