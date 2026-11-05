<?php

namespace App\Filament\Admin\Resources\EmailTemplates\Schemas;

use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class EmailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Email & Alert Template Configuration')
                    ->columns(2)
                    ->schema([
                        TextInput::make('code')
                            ->label('Template Code')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(80),

                        TextInput::make('name')
                            ->label('Template Name')
                            ->required()
                            ->maxLength(120),

                        TextInput::make('subject')
                            ->label('Email Subject (Supports {placeholders})')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),

                        TagsInput::make('available_placeholders')
                            ->label('Available Dynamic Variables')
                            ->placeholder('e.g., user_name, website_name')
                            ->columnSpanFull(),

                        RichEditor::make('body_html')
                            ->label('HTML Template Body')
                            ->required()
                            ->columnSpanFull(),

                        Toggle::make('is_active')
                            ->label('Template Active')
                            ->default(true),
                    ]),
            ]);

    }
}
