<?php

namespace App\Filament\App\Resources\WebsiteResource\Pages;

use App\Filament\App\Resources\WebsiteResource;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListWebsites extends ListRecords
{
    protected static string $resource = WebsiteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->before(function (CreateAction $action): void {
                    /** @var Workspace $workspace */
                    $workspace = Filament::getTenant();
                    if (! EntitlementService::canAddWebsite($workspace)) {
                        Notification::make()
                            ->title('Website Limit Reached or Trial Expired')
                            ->body('Please upgrade your subscription plan to add more websites.')
                            ->danger()
                            ->send();

                        $action->halt();
                    }
                }),
        ];
    }
}

