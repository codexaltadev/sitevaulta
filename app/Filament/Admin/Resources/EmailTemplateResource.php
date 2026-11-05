<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\EmailTemplateResource\Pages;
use App\Models\EmailTemplate;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class EmailTemplateResource extends Resource
{
    protected static ?string $model = EmailTemplate::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string | UnitEnum | null $navigationGroup = 'System & Configuration';

    protected static ?string $navigationLabel = 'Dynamic Email Templates';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->badge()
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('subject')
                    ->limit(50),
                IconColumn::make('is_active')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListEmailTemplates::route('/'),
            'create' => Pages\CreateEmailTemplate::route('/create'),
            'edit' => Pages\EditEmailTemplate::route('/{record}/edit'),
        ];
    }
}

