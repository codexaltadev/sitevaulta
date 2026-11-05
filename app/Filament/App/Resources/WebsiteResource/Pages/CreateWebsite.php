<?php

namespace App\Filament\App\Resources\WebsiteResource\Pages;

use App\Filament\App\Resources\WebsiteResource;
use App\Models\Website;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Verification\DomainVerificationService;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;

class CreateWebsite extends CreateRecord
{
    protected static string $resource = WebsiteResource::class;

    protected function beforeCreate(): void
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();
        if (! EntitlementService::canAddWebsite($workspace)) {
            Notification::make()
                ->title('Website Quota Reached or Subscription Expired')
                ->body('Your current plan limit for websites has been reached. Upgrade your plan in Billing to add more websites.')
                ->danger()
                ->send();

            throw new Halt();
        }
    }

    protected function afterCreate(): void
    {
        /** @var Website $website */
        $website = $this->record;
        DomainVerificationService::ensureChallengeExists($website);
    }
}

