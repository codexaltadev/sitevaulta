<?php

namespace App\Filament\Admin\Resources\Workspaces\Pages;

use App\Filament\Admin\Resources\Workspaces\WorkspaceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWorkspaces extends ListRecords
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
