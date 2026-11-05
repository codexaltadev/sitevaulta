<?php

namespace App\Filament\Admin\Resources\Workspaces\Schemas;

use App\Models\User;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class WorkspaceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Customer Workspace Details')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Workspace Name')
                            ->required()
                            ->maxLength(150),

                        TextInput::make('slug')
                            ->label('Workspace URL Slug')
                            ->required()
                            ->maxLength(150),

                        Select::make('owner_id')
                            ->label('Workspace Owner')
                            ->options(fn () => User::pluck('email', 'id'))
                            ->searchable()
                            ->required(),

                        Select::make('billing_currency')
                            ->label('Billing Currency')
                            ->options([
                                'BDT' => 'BDT (৳)',
                                'USD' => 'USD ($)',
                            ])
                            ->default('BDT')
                            ->required(),

                        TextInput::make('timezone')
                            ->default('Asia/Dhaka')
                            ->required(),

                        Toggle::make('is_suspended')
                            ->label('Suspend Workspace')
                            ->default(false),
                    ]),
            ]);

    }
}
