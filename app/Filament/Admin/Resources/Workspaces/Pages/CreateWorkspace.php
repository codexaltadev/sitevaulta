<?php

namespace App\Filament\Admin\Resources\Workspaces\Pages;

use App\Filament\Admin\Resources\Workspaces\WorkspaceResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkspace extends CreateRecord
{
    protected static string $resource = WorkspaceResource::class;
}
