<?php

namespace App\Filament\App\Resources\BackupTemplates\Schemas;

use App\Enums\BackupScope;
use App\Enums\BackupStrategy;
use App\Enums\ScheduleFrequency;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class BackupTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Reusable Backup Configuration Template')
                    ->description('Create reusable templates without storage secrets and apply them across multiple websites.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Template Name')
                            ->placeholder('e.g., Standard Agency Daily Incremental (14d)')
                            ->required()
                            ->maxLength(120)
                            ->columnSpanFull(),

                        Select::make('scope')
                            ->label('Backup Scope')
                            ->options(BackupScope::options())
                            ->default(BackupScope::FullStack->value)
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($state === BackupScope::DatabaseOnly->value) {
                                    $set('strategy', BackupStrategy::FullOnly->value);
                                    $set('full_reset_every_n', 1);
                                }
                            })
                            ->required(),

                        Select::make('strategy')
                            ->label('Backup Strategy')
                            ->options(BackupStrategy::options())
                            ->default(BackupStrategy::IncrementalWithPeriodicFull->value)
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($state === BackupStrategy::FullOnly->value) {
                                    $set('full_reset_every_n', 1);
                                } else {
                                    $set('full_reset_every_n', 7);
                                }
                            })
                            ->required(),

                        TextInput::make('full_reset_every_n')
                            ->label('Start New Full Backup Every N Incrementals')
                            ->numeric()
                            ->minValue(1)
                            ->default(7)
                            ->visible(fn (Get $get): bool => $get('strategy') !== BackupStrategy::FullOnly->value)
                            ->required(fn (Get $get): bool => $get('strategy') !== BackupStrategy::FullOnly->value),

                        Select::make('schedule_frequency')
                            ->label('Schedule Frequency')
                            ->options(ScheduleFrequency::options())
                            ->default(ScheduleFrequency::Daily->value)
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                if ($state && ($freq = ScheduleFrequency::tryFrom($state))) {
                                    $set('interval_minutes', $freq->defaultIntervalMinutes());
                                    $set('cron_expression', $freq->defaultCronExpression());
                                }
                            })
                            ->required(),

                        TextInput::make('interval_minutes')
                            ->label('Interval in Minutes (I)')
                            ->numeric()
                            ->default(1440)
                            ->readOnly(fn (Get $get): bool => $get('schedule_frequency') !== ScheduleFrequency::CustomCron->value)
                            ->required(),

                        TextInput::make('cron_expression')
                            ->label('Cron Expression')
                            ->default('0 2 * * *')
                            ->readOnly(fn (Get $get): bool => $get('schedule_frequency') !== ScheduleFrequency::CustomCron->value)
                            ->required(),

                        TextInput::make('retention_days')
                            ->label('Retention Period in Days (R)')
                            ->numeric()
                            ->default(14)
                            ->required(),

                        Toggle::make('enable_encryption')
                            ->label('Enable AES-256-GCM Archive Encryption by Default')
                            ->default(true),

                        TagsInput::make('exclude_paths')
                            ->label('Excluded Directories / Patterns')
                            ->default(['.git', 'node_modules', 'vendor', 'storage/logs/*.log'])
                            ->visible(fn (Get $get): bool => $get('scope') !== BackupScope::DatabaseOnly->value)
                            ->columnSpanFull(),
                    ]),
            ]);

    }
}
