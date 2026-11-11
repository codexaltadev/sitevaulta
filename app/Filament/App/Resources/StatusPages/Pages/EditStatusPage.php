<?php

namespace App\Filament\App\Resources\StatusPages\Pages;

use App\Filament\App\Resources\StatusPages\StatusPageResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditStatusPage extends EditRecord
{
    protected static string $resource = StatusPageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('open')
                ->label('Open public page')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => $this->getRecord()->publicUrl(), shouldOpenInNewTab: true),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
