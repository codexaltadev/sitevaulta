<?php

namespace App\Filament\Admin\Resources\EmailTemplates\Pages;

use App\Filament\Admin\Resources\EmailTemplates\EmailTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmailTemplate extends CreateRecord
{
    protected static string $resource = EmailTemplateResource::class;
}
