<?php

namespace App\Filament\App\Resources\VerifiedDomains\Pages;

use App\Filament\App\Resources\VerifiedDomains\VerifiedDomainResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListVerifiedDomains extends ListRecords
{
    protected static string $resource = VerifiedDomainResource::class;

    public function getSubheading(): ?string
    {
        return 'Every domain whose ownership is proven. Websites verified in Backup Management appear here automatically (Source: Backup website). Each sub-domain needs its own verification.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Add domain')
                ->modalDescription('After adding, follow the instructions to place a verification token, then click "Verify now".'),
        ];
    }
}
