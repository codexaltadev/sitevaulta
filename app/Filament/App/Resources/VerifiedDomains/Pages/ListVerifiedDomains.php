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
        return 'One place for domain ownership. Every backup website\'s domain is listed here (Source: Backup website) and can be verified from here or from Backup Management. Each sub-domain needs its own verification.';
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
