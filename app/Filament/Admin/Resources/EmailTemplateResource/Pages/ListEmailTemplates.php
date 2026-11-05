<?php

namespace App\Filament\Admin\Resources\EmailTemplateResource\Pages;

use App\Filament\Admin\Resources\EmailTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmailTemplates extends ListRecords
{
    protected static string $resource = EmailTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

