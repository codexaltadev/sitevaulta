<?php

namespace App\Filament\App\Resources\StatusPages\Pages;

use App\Filament\App\Resources\StatusPages\StatusPageResource;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateStatusPage extends CreateRecord
{
    protected static string $resource = StatusPageResource::class;

    protected function beforeCreate(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();

        if (! EntitlementService::canAddStatusPage($workspace)) {
            Notification::make()
                ->title('Status Page Quota Reached or Subscription Expired')
                ->body('Your current plan allows '.EntitlementService::statusPageLimit($workspace).' status page(s). Upgrade your plan in Billing to add more.')
                ->danger()
                ->send();

            throw new Halt;
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
