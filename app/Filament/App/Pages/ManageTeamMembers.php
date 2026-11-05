<?php

namespace App\Filament\App\Pages;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Security\AuditLogService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;
use UnitEnum;

class ManageTeamMembers extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Workspace & Billing';

    protected static ?string $navigationLabel = 'Team Members & Roles';

    protected static ?string $title = 'Workspace Team Members & Access Control';

    protected static ?string $slug = 'team-members';

    protected static ?int $navigationSort = 20;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('inviteMember')
                ->label('Add Team Member')
                ->icon(Heroicon::OutlinedUserPlus)
                ->schema([
                    TextInput::make('name')
                        ->label('Member Name')
                        ->required()
                        ->maxLength(120),
                    TextInput::make('email')
                        ->label('Email Address')
                        ->email()
                        ->required()
                        ->maxLength(191),
                    TextInput::make('password')
                        ->label('Initial Password (if new account)')
                        ->password()
                        ->revealable()
                        ->default('password123'),
                    Select::make('role')
                        ->label('Workspace Role')
                        ->options(WorkspaceRole::options())
                        ->default(WorkspaceRole::Operator->value)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Workspace $workspace */
                    $workspace = Filament::getTenant();
                    $sub = $workspace->currentSubscription;
                    $maxMembers = $sub ? $sub->effectiveLimit('max_team_members') : 1;

                    if ($workspace->members()->count() >= $maxMembers) {
                        Notification::make()
                            ->title("Your current plan allows up to {$maxMembers} team member(s). Please upgrade your plan to add more.")
                            ->danger()
                            ->send();

                        return;
                    }

                    $user = User::firstOrCreate(
                        ['email' => strtolower(trim($data['email']))],
                        [
                            'name' => $data['name'],
                            'password' => Hash::make($data['password'] ?: 'password123'),
                            'email_verified_at' => now(),
                        ]
                    );

                    $workspace->members()->syncWithoutDetaching([
                        $user->id => ['role' => $data['role']],
                    ]);

                    AuditLogService::log(
                        event: 'workspace.member.added',
                        auditable: $user,
                        metadata: ['role' => $data['role']],
                        workspaceId: $workspace->id
                    );

                    Notification::make()
                        ->title("Team member {$user->email} added as {$data['role']}.")
                        ->success()
                        ->send();
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        /** @var Workspace $workspace */
        $workspace = Filament::getTenant();

        return $table
            ->query($workspace->members()->getQuery())
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable(),
                TextColumn::make('pivot.role')
                    ->label('Workspace Role')
                    ->badge(),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime(),
            ])
            ->recordActions([
                Action::make('remove')
                    ->label('Remove')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->id !== $workspace->owner_id)
                    ->action(function (User $record) use ($workspace): void {
                        $workspace->members()->detach($record->id);
                        Notification::make()
                            ->title('Team member removed from workspace.')
                            ->success()
                            ->send();
                    }),
            ]);
    }
}
