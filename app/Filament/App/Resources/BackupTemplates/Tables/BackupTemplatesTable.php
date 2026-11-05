<?php

namespace App\Filament\App\Resources\BackupTemplates\Tables;

use App\Models\BackupTemplate;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BackupTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('scope')
                    ->badge(),
                TextColumn::make('strategy')
                    ->badge(),
                TextColumn::make('schedule_frequency')
                    ->label('Schedule')
                    ->badge(),
                TextColumn::make('retention_days')
                    ->label('Retention')
                    ->suffix(' days'),
                IconColumn::make('enable_encryption')
                    ->label('Encrypted')
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('applyToExistingPolicies')
                    ->label('Sync to Linked Policies')
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription('Explicitly apply this template\'s schedule, scope, retention, and exclude rules to all backup policies linked to this template.')
                    ->action(function (BackupTemplate $record): void {
                        $count = $record->backupPolicies()->update([
                            'scope' => $record->scope,
                            'strategy' => $record->strategy,
                            'full_reset_every_n' => $record->full_reset_every_n,
                            'schedule_frequency' => $record->schedule_frequency,
                            'interval_minutes' => $record->interval_minutes,
                            'cron_expression' => $record->cron_expression,
                            'retention_days' => $record->retention_days,
                            'exclude_paths' => $record->exclude_paths,
                        ]);

                        Notification::make()
                            ->title("Synchronized template changes to {$count} linked backup policy/policies.")
                            ->success()
                            ->send();
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
