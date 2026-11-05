<?php

namespace App\Filament\Admin\Resources\AuditLogs\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('event')
                    ->badge()
                    ->searchable(),
                TextColumn::make('workspace.name')
                    ->label('Workspace')
                    ->placeholder('Global / System'),
                TextColumn::make('user.email')
                    ->label('Actor')
                    ->placeholder('System / Worker'),
                TextColumn::make('ip_address')
                    ->label('IP Address'),
                TextColumn::make('created_at')
                    ->dateTime(),
            ]);

    }
}
