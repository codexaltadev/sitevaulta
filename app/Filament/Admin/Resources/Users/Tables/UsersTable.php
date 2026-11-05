<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label('Shield Roles')
                    ->badge(),
                IconColumn::make('can_access_admin_panel')
                    ->label('Admin Access')
                    ->boolean(),
                IconColumn::make('is_suspended')
                    ->label('Suspended')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
