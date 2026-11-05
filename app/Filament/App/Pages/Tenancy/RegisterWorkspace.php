<?php

namespace App\Filament\App\Pages\Tenancy;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Security\AuditLogService;
use App\Services\Settings\SystemSettingsService;
use App\Services\Settings\WorkspaceSettingsService;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RegisterWorkspace extends RegisterTenant
{
    public static function getLabel(): string
    {
        return 'Create Workspace';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Workspace / Agency Name')
                    ->placeholder('e.g., My Web Agency or Personal Sites')
                    ->required()
                    ->maxLength(120)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, callable $set): void {
                        if ($state) {
                            $set('slug', Str::slug($state).'-'.Str::lower(Str::random(4)));
                        }
                    }),

                TextInput::make('slug')
                    ->label('Workspace URL Slug')
                    ->required()
                    ->maxLength(140)
                    ->unique(Workspace::class, 'slug'),

                Select::make('billing_currency')
                    ->label('Preferred Billing Currency')
                    ->options([
                        'BDT' => '🇧🇩 BDT (৳) — SSLCOMMERZ / bKash / Nagad / Local Cards',
                        'USD' => '🌐 USD ($) — Stripe / PayPal International',
                    ])
                    ->default(fn () => SystemSettingsService::get('general', 'default_currency', 'BDT'))
                    ->required(),

                Select::make('timezone')
                    ->label('Default Timezone')
                    ->options([
                        'Asia/Dhaka' => 'Asia/Dhaka (GMT+6)',
                        'UTC' => 'UTC (GMT+0)',
                        'America/New_York' => 'America/New_York (EST)',
                        'Europe/London' => 'Europe/London (GMT)',
                        'Asia/Kolkata' => 'Asia/Kolkata (IST)',
                        'Asia/Singapore' => 'Asia/Singapore (SGT)',
                    ])
                    ->default(fn () => SystemSettingsService::get('general', 'default_timezone', 'Asia/Dhaka'))
                    ->searchable()
                    ->required(),
            ]);
    }

    protected function handleRegistration(array $data): Model
    {
        $user = auth()->user();

        $workspace = Workspace::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'owner_id' => $user->id,
            'billing_currency' => $data['billing_currency'] ?? 'BDT',
            'timezone' => $data['timezone'] ?? 'Asia/Dhaka',
        ]);

        $workspace->members()->attach($user->id, [
            'role' => WorkspaceRole::Owner->value,
        ]);

        WorkspaceSettingsService::initializeDefaultsForWorkspace($workspace);
        EntitlementService::provisionFreeTrial($workspace);

        AuditLogService::log(
            event: 'workspace.created',
            auditable: $workspace,
            metadata: ['name' => $workspace->name, 'currency' => $workspace->billing_currency],
            workspaceId: $workspace->id,
            userId: $user->id
        );

        return $workspace;
    }
}
