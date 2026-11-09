<?php

namespace App\Filament\Admin\Resources\EmailPackages\Pages;

use App\Filament\Admin\Resources\EmailPackages\EmailPackageResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailPackage extends CreateRecord
{
    protected static string $resource = EmailPackageResource::class;
}

