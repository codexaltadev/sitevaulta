<?php

namespace App\Filament\App\Resources\VerifiedDomains;

use App\Filament\App\Resources\VerifiedDomains\Pages\ListVerifiedDomains;
use App\Filament\App\Resources\VerifiedDomains\Tables\VerifiedDomainsTable;
use App\Models\VerifiedDomain;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class VerifiedDomainResource extends Resource
{
    protected static ?string $model = VerifiedDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Uptime Monitoring';

    protected static ?string $navigationLabel = 'Verified domains';

    protected static ?string $modelLabel = 'verified domain';

    protected static ?string $slug = 'verified-domains';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('domain')
                ->label('Domain you own')
                ->placeholder('example.com')
                ->helperText('Proving ownership lets you run port checks on this exact domain (www. included) and the IPs it points to. Verify each sub-domain separately.')
                ->required()
                ->maxLength(255)
                ->regex('/^(https?:\/\/)?[A-Za-z0-9][A-Za-z0-9.\-]*\.[A-Za-z]{2,}\/?$/')
                ->validationMessages(['regex' => 'Enter a domain such as example.com.'])
                ->dehydrateStateUsing(fn (string $state): string => VerifiedDomain::normalize($state))
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('workspace_id', Filament::getTenant()?->getKey())),
        ]);
    }

    public static function table(Table $table): Table
    {
        return VerifiedDomainsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVerifiedDomains::route('/'),
        ];
    }
}
