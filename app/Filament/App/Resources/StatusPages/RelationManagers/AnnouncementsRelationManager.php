<?php

namespace App\Filament\App\Resources\StatusPages\RelationManagers;

use App\Models\StatusPageAnnouncement;
use App\Services\Monitoring\StatusPageNotifier;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Announcements on the public status page: planned maintenance, incident updates or general news.
 * Open ones show at the top of the page; resolved ones move to the 14-day history.
 */
class AnnouncementsRelationManager extends RelationManager
{
    protected static string $relationship = 'announcements';

    protected static ?string $title = 'Announcements';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                ToggleButtons::make('type')
                    ->options(StatusPageAnnouncement::TYPES)
                    ->icons(['info' => Heroicon::OutlinedInformationCircle, 'maintenance' => Heroicon::OutlinedWrenchScrewdriver, 'incident' => Heroicon::OutlinedExclamationTriangle])
                    ->colors(['info' => 'success', 'maintenance' => 'info', 'incident' => 'danger'])
                    ->inline()
                    ->default('info')
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('title')
                    ->placeholder('e.g. Planned database upgrade tonight 23:00–23:30')
                    ->required()
                    ->maxLength(150)
                    ->columnSpanFull(),
                Textarea::make('body')
                    ->label('Details')
                    ->rows(4)
                    ->maxLength(5000)
                    ->columnSpanFull(),
                Toggle::make('notify_subscribers')
                    ->label('Email it to subscribers')
                    ->default(true)
                    ->visible(fn (string $operation): bool => $operation === 'create'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->emptyStateHeading('No announcements')
            ->emptyStateDescription('Tell visitors about planned maintenance or post updates during an incident.')
            ->columns([
                TextColumn::make('title')
                    ->weight('semibold')
                    ->description(fn (StatusPageAnnouncement $record): ?string => str($record->body)->limit(80)->toString() ?: null)
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (StatusPageAnnouncement $record): string => $record->typeLabel())
                    ->color(fn (string $state): string => ['incident' => 'danger', 'maintenance' => 'info'][$state] ?? 'success'),
                TextColumn::make('resolved_at')
                    ->label('State')
                    ->badge()
                    ->state(fn (StatusPageAnnouncement $record): string => $record->resolved_at ? 'Resolved' : 'Shown on the page')
                    ->color(fn (StatusPageAnnouncement $record): string => $record->resolved_at ? 'gray' : 'warning'),
                TextColumn::make('created_at')
                    ->label('Posted')
                    ->since(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Post announcement')
                    ->using(function (array $data): StatusPageAnnouncement {
                        $notify = (bool) ($data['notify_subscribers'] ?? true);
                        unset($data['notify_subscribers']);

                        /** @var StatusPageAnnouncement $announcement */
                        $announcement = $this->getRelationship()->create($data);

                        if ($notify) {
                            app(StatusPageNotifier::class)->announcementPosted($announcement);
                        }

                        return $announcement;
                    }),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->label('Resolve')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->visible(fn (StatusPageAnnouncement $record): bool => $record->resolved_at === null)
                    ->action(function (StatusPageAnnouncement $record): void {
                        $record->update(['resolved_at' => now()]);
                        Notification::make()->title('Announcement resolved — moved to the history')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
