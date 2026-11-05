<?php

namespace App\Filament\Admin\Resources;

use App\Filament\Admin\Resources\WorkspaceResource\Pages;
use App\Models\User;
use App\Models\Workspace;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class WorkspaceResource extends Resource
{
    protected static ?string $model = Workspace::class;

    protected static string | BackedEnum | null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static string | UnitEnum | null $navigationGroup = 'Tenants & Access';

    protected static ?string $navigationLabel = 'Customer Workspaces';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
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

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('slug')
                    ->badge(),
                TextColumn::make('owner.email')
                    ->label('Owner'),
                TextColumn::make('currentSubscription.plan.name')
                    ->label('Plan')
                    ->badge(),
                TextColumn::make('currentSubscription.status')
                    ->label('Sub Status')
                    ->badge(),
                TextColumn::make('websites_count')
                    ->counts('websites')
                    ->label('Sites'),
                IconColumn::make('is_suspended')
                    ->label('Suspended')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('extendTrial7Days')
                    ->label('+7d Trial')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('info')
                    ->action(function (Workspace $record): void {
                        $sub = $record->currentSubscription;
                        if ($sub) {
                            $base = $sub->trial_ends_at && $sub->trial_ends_at->isFuture() ? $sub->trial_ends_at : now();
                            $sub->update([
                                'trial_ends_at' => $base->copy()->addDays(7),
                                'current_period_ends_at' => $base->copy()->addDays(7),
                            ]);
                            Notification::make()->title('Extended trial by 7 days.')->success()->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkspaces::route('/'),
            'create' => Pages\CreateWorkspace::route('/create'),
            'edit' => Pages\EditWorkspace::route('/{record}/edit'),
        ];
    }
}
