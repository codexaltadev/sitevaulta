<?php

namespace App\Filament\Admin\Resources\Workspaces\Pages;

use App\Filament\Admin\Resources\Workspaces\WorkspaceResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditWorkspace extends EditRecord
{
    protected static string $resource = WorkspaceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
