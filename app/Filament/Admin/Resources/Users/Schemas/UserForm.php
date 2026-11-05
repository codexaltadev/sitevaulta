<?php

namespace App\Filament\Admin\Resources\Users\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('User Account & Filament Shield RBAC')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(150),

                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(150),

                        TextInput::make('password')
                            ->password()
                            ->dehydrateStateUsing(fn (?string $state): ?string => filled($state) ? Hash::make($state) : null)
                            ->dehydrated(fn (?string $state): bool => filled($state))
                            ->required(fn (string $operation): bool => $operation === 'create'),

                        Select::make('roles')
                            ->label('Filament Shield Staff Roles')
                            ->relationship('roles', 'name')
                            ->multiple()
                            ->preload(),

                        Toggle::make('can_access_admin_panel')
                            ->label('Can Access /admin Panel')
                            ->default(false),

                        Toggle::make('is_suspended')
                            ->label('Account Suspended')
                            ->default(false),
                    ]),
            ]);

    }
}
