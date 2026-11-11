<?php

namespace App\Filament\App\Resources\MonitorIncidents\Pages;

use App\Enums\MonitorIncidentStatus;
use App\Filament\App\Resources\MonitorIncidents\MonitorIncidentResource;
use App\Filament\App\Resources\Monitors\MonitorResource;
use App\Models\MonitorIncident;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UptimeRobot-style incident detail: root cause + region, status and live duration,
 * the request that failed, the response that came back, and the activity log.
 *
 * @property MonitorIncident $record
 */
class ViewMonitorIncident extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MonitorIncidentResource::class;

    protected string $view = 'filament.app.resources.monitor-incidents.pages.view-monitor-incident';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->record->loadMissing('monitor');
    }

    public function getTitle(): string|Htmlable
    {
        $state = $this->record->status === MonitorIncidentStatus::Ongoing ? 'Ongoing' : 'Resolved';

        return "{$state} incident on {$this->record->monitor->name}";
    }

    public function getBreadcrumbs(): array
    {
        return [
            MonitorIncidentResource::getUrl('index') => 'Incidents',
            $this->record->monitor->name,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('goToMonitor')
                ->label('Go to monitor')
                ->icon(Heroicon::OutlinedSignal)
                ->color('gray')
                ->url(fn (): string => MonitorResource::getUrl('view', ['record' => $this->record->monitor_id])),

            Action::make('toggleVisibility')
                ->label(fn (): string => $this->record->is_excluded_from_reports ? 'Include in reports' : 'Exclude from reports')
                ->icon(fn (): Heroicon => $this->record->is_excluded_from_reports ? Heroicon::OutlinedEye : Heroicon::OutlinedEyeSlash)
                ->color('gray')
                ->action(fn () => $this->record->update([
                    'is_excluded_from_reports' => ! $this->record->is_excluded_from_reports,
                ])),

            Action::make('downloadResponse')
                ->label('Download response')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->action(fn (): StreamedResponse => $this->downloadResponse()),
        ];
    }

    public function downloadResponse(): StreamedResponse
    {
        $payload = [
            'incident' => $this->record->uuid,
            'monitor' => $this->record->monitor->name,
            'root_cause' => $this->record->root_cause,
            'started_at' => $this->record->started_at->toIso8601String(),
            'resolved_at' => $this->record->resolved_at?->toIso8601String(),
            'request' => $this->record->request_snapshot,
            'response' => [
                'headers' => $this->record->response_headers,
                'body' => $this->record->response_body,
            ],
        ];

        return response()->streamDownload(
            function () use ($payload): void {
                echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            },
            "incident-{$this->record->id}-response.json",
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $incident = $this->record;
        $request = $incident->request_snapshot ?? [];

        return [
            'incident' => $incident,
            'timezone' => $incident->monitor->workspace?->timezone ?: config('app.timezone'),
            'isOngoing' => $incident->status === MonitorIncidentStatus::Ongoing,
            'durationSeconds' => $incident->duration_seconds ?? (int) $incident->started_at->diffInSeconds(now()),
            'requestLine' => isset($request['url'])
                ? trim(($request['method'] ?? 'GET').' '.$request['url'])
                : trim(($request['host'] ?? '').':'.($request['port'] ?? '')),
            'requestHeaders' => self::formatHeaders($request['headers'] ?? []),
            'responseHeaders' => self::formatHeaders($incident->response_headers ?? []),
            'events' => $incident->events()->orderByDesc('occurred_at')->orderByDesc('id')->get(),
        ];
    }

    /**
     * @param  array<string, string|list<string>>  $headers
     */
    private static function formatHeaders(array $headers): string
    {
        return collect($headers)
            ->map(fn (string|array $value, string $name): string => $name.': '.implode(', ', (array) $value))
            ->implode("\n");
    }
}
