<?php

namespace App\Filament\App\Resources\ServerConnections\Tables;

use App\Enums\FileProtocol;
use App\Models\ServerConnection;
use App\Services\Connectors\ServerConnectorManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ServerConnectionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Connection Name')
                    ->searchable(),
                TextColumn::make('website.name')
                    ->label('Website'),
                TextColumn::make('file_protocol')
                    ->label('File Protocol')
                    ->badge(),
                TextColumn::make('db_driver')
                    ->label('Database Engine')
                    ->badge(),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'connected', 'healthy' => 'success',
                        'error' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('last_tested_at')
                    ->dateTime()
                    ->placeholder('Never tested'),
            ])
            ->recordActions([
                Action::make('downloadAgentScript')
                    ->label('Download Agent PHP')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('success')
                    ->visible(fn (ServerConnection $record): bool => $record->file_protocol === FileProtocol::VaultixAgent)
                    ->action(function (ServerConnection $record) {
                        $token = (string) ($record->file_credentials['agent_token'] ?? 'CHANGE_ME_SECRET_TOKEN');
                        $script = "<?php\n"
                            ."/**\n"
                            ." * SiteVaulta Standalone Agent Connector (Framework-Agnostic)\n"
                            ." * Upload to your website webroot as _vaultix-agent.php\n"
                            ." */\n"
                            .'$expectedToken = '.var_export($token, true).";\n"
                            ."\$authHeader = \$_SERVER['HTTP_AUTHORIZATION'] ?? '';\n"
                            ."if (\$authHeader !== 'Bearer ' . \$expectedToken) {\n"
                            ."    http_response_code(401);\n"
                            ."    echo json_encode(['error' => 'Unauthorized Vaultix Agent Token']);\n"
                            ."    exit;\n"
                            ."}\n"
                            ."header('Content-Type: application/json');\n"
                            ."echo json_encode(['status' => 'ok', 'php_version' => PHP_VERSION, 'root' => __DIR__]);\n";

                        return response()->streamDownload(
                            fn () => print ($script),
                            'vaultix-agent.php'
                        );
                    }),

                Action::make('testConnection')
                    ->label('Test Connection')
                    ->icon(Heroicon::OutlinedBolt)
                    ->color('info')
                    ->action(function (ServerConnection $record): void {
                        $result = ServerConnectorManager::testConnection($record);
                        if ($result->success) {
                            Notification::make()
                                ->title('Server & Database Connection Verified')
                                ->body($result->message)
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('Connection Test Failed')
                                ->body($result->message)
                                ->danger()
                                ->send();
                        }
                    }),

                EditAction::make(),
                DeleteAction::make(),
            ]);

    }
}
