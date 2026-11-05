<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\UserResource\Pages;
use App\Models\User;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string | UnitEnum | null $navigationGroup = 'Tenants & Access';

    protected static ?string $navigationLabel = 'Users & Shield Roles';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
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

    public static function table(Table $table): Table
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
