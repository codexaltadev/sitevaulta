<?php

namespace App\Filament\App\Resources\Monitors\Pages;

use App\Enums\MonitorStatus;
use App\Filament\App\Resources\Monitors\MonitorResource;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Services\Billing\EntitlementService;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorUptimeStatistics;
use App\Services\Monitoring\MonthlyUptimeReport;
use App\Services\Monitoring\WebsiteMonitorSync;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * UptimeRobot-style monitors overview: dense one-line rows with 24 hourly bars on the left,
 * "Current status" and "Last 24 hours" widgets on the right.
 */
class ListMonitors extends Page
{
    protected static string $resource = MonitorResource::class;

    protected string $view = 'filament.app.resources.monitors.pages.list-monitors';

    protected static ?string $title = 'Monitors';

    /**
     * @var array<string, string>
     */
    public const SORT_OPTIONS = [
        'down_first' => 'Down first',
        'up_first' => 'Up first',
        'name' => 'Name (A–Z)',
        'newest' => 'Newest first',
    ];

    /**
     * @var array<string, string>
     */
    public const STATUS_FILTER_OPTIONS = [
        'all' => 'All statuses',
        'down' => 'Down',
        'up' => 'Up',
        'paused' => 'Paused',
        'pending' => 'Pending',
    ];

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'status')]
    public string $statusFilter = 'all';

    #[Url]
    public string $sort = 'down_first';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importWebsites')
                ->label('Import from websites')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Creates an HTTP(s) monitor for every website in this workspace that does not have one yet.')
                ->action(fn () => $this->importWebsites()),

            Action::make('monthlyReport')
                ->label('Monthly report')
                ->icon(Heroicon::OutlinedDocumentChartBar)
                ->color('gray')
                ->modalHeading('Download the monthly uptime report')
                ->modalDescription('Uptime %, incidents, downtime and response time of every monitor. It is also emailed on the 1st of each month (Workspace settings → Notification Preferences).')
                ->modalSubmitActionLabel('Download PDF')
                ->schema([
                    Select::make('month')
                        ->options(self::reportMonthOptions())
                        ->default(now('UTC')->subMonthNoOverflow()->format('Y-m'))
                        ->required(),
                ])
                ->action(fn (array $data) => $this->downloadMonthlyReport((string) $data['month'])),

            Action::make('create')
                ->label('New')
                ->icon(Heroicon::OutlinedPlus)
                ->url(MonitorResource::getUrl('create')),
        ];
    }

    /**
     * This month and the eleven before it, newest first ("2026-11" => "November 2026 (so far)").
     *
     * @return array<string, string>
     */
    public static function reportMonthOptions(): array
    {
        return collect(range(0, 11))
            ->mapWithKeys(function (int $monthsAgo): array {
                $month = CarbonImmutable::now('UTC')->startOfMonth()->subMonthsNoOverflow($monthsAgo);

                return [$month->format('Y-m') => $month->format('F Y').($monthsAgo === 0 ? ' (so far)' : '')];
            })
            ->all();
    }

    public function downloadMonthlyReport(string $month): StreamedResponse
    {
        abort_unless(array_key_exists($month, self::reportMonthOptions()), 422);

        $data = app(MonthlyUptimeReport::class)->build($this->workspace(), CarbonImmutable::createFromFormat('Y-m-d', "{$month}-01", 'UTC'));
        $pdf = Pdf::loadView('reports.monthly-uptime', $data)->setPaper('a4');

        return response()->streamDownload(fn () => print $pdf->output(), "uptime-report-{$month}.pdf", ['Content-Type' => 'application/pdf']);
    }

    public function pauseMonitor(int $monitorId): void
    {
        $this->findMonitor($monitorId)->update(['status' => MonitorStatus::Paused]);

        Notification::make()->title('Monitor paused')->success()->send();
    }

    public function resumeMonitor(int $monitorId): void
    {
        $this->findMonitor($monitorId)->update([
            'status' => MonitorStatus::Pending,
            'last_checked_at' => null,
        ]);

        Notification::make()->title('Monitor resumed — it will be checked within a minute')->success()->send();
    }

    public function checkMonitorNow(int $monitorId): void
    {
        $monitor = $this->findMonitor($monitorId);

        if ($monitor->status === MonitorStatus::Paused) {
            return;
        }

        $check = app(MonitorCheckRunner::class)->run($monitor);

        $notification = Notification::make()->title("{$monitor->name} is {$check->status->label()}");
        $check->status === MonitorStatus::Up ? $notification->success() : $notification->danger();
        $notification->send();
    }

    public function deleteMonitor(int $monitorId): void
    {
        $this->findMonitor($monitorId)->delete();

        Notification::make()->title('Monitor deleted')->success()->send();
    }

    public function importWebsites(): void
    {
        $workspace = $this->workspace();

        $unmonitoredWebsites = $workspace->websites()
            ->whereNotIn('id', $workspace->monitors()->whereNotNull('website_id')->select('website_id'))
            ->get(['id', 'name', 'url', 'domain']);

        if ($unmonitoredWebsites->isEmpty()) {
            Notification::make()->title('All websites are already monitored')->success()->send();

            return;
        }

        $remainingSlots = EntitlementService::remainingMonitorSlots($workspace);
        $importedCount = 0;

        foreach ($unmonitoredWebsites as $website) {
            $host = Monitor::normalizeHost(WebsiteMonitorSync::monitorUrlFor($website));
            $sameHost = $workspace->monitors()->where('target_host', $host)->first();

            // Already monitored by hand: link it (no extra slot, no duplicate).
            if ($sameHost !== null) {
                $sameHost->website_id ??= $website->id;
                $sameHost->save();
                $importedCount++;

                continue;
            }

            if ($remainingSlots <= 0) {
                continue;
            }

            $workspace->monitors()->create([
                'website_id' => $website->id,
                'name' => $website->name ?: $website->domain,
                'url' => WebsiteMonitorSync::monitorUrlFor($website),
                'interval_seconds' => max(300, EntitlementService::minMonitorIntervalSeconds($workspace)),
            ]);
            $remainingSlots--;
            $importedCount++;
        }

        $skippedCount = $unmonitoredWebsites->count() - $importedCount;

        if ($skippedCount > 0) {
            Notification::make()
                ->title("Imported {$importedCount} monitor(s); {$skippedCount} skipped")
                ->body('Your plan allows '.EntitlementService::monitorLimit($workspace).' monitors. Upgrade in Billing to monitor every website.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title("Imported {$importedCount} website monitor(s)")
            ->success()
            ->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $workspace = $this->workspace();
        $statistics = app(MonitorUptimeStatistics::class);
        $monitors = $this->filteredMonitors();

        return [
            'monitors' => $monitors,
            'monitorStats' => $statistics->hourlyBarsWithUptime($monitors->modelKeys()),
            'ownedHosts' => app(HostOwnershipVerifier::class)->ownedHostLabels($workspace, $monitors->pluck('target_host')),
            'summary' => $statistics->workspaceSummary($workspace),
            'monitorLimit' => EntitlementService::monitorLimit($workspace),
            'sortOptions' => self::SORT_OPTIONS,
            'statusFilterOptions' => self::STATUS_FILTER_OPTIONS,
        ];
    }

    /**
     * @return Collection<int, Monitor>
     */
    private function filteredMonitors(): Collection
    {
        $search = trim($this->search);

        return $this->workspace()->monitors()
            ->topLevel()
            ->with(['ongoingIncident', 'maintenanceWindows', 'workspace', 'children'])
            ->when($search !== '', fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('url', 'like', "%{$search}%")
                    ->orWhere('host', 'like', "%{$search}%");
            }))
            ->when(
                MonitorStatus::tryFrom($this->statusFilter),
                fn (Builder $query, MonitorStatus $status) => $query->where('status', $status),
            )
            ->tap(fn (Builder $query) => $this->applySort($query))
            ->get();
    }

    private function applySort(Builder $query): void
    {
        match ($this->sort) {
            'up_first' => $query->orderByRaw("case status when 'up' then 0 when 'pending' then 1 when 'down' then 2 else 3 end"),
            'name' => $query->orderBy('name'),
            'newest' => $query->latest('id'),
            default => $query->orderByRaw("case status when 'down' then 0 when 'pending' then 1 when 'up' then 2 else 3 end"),
        };

        $query->orderBy('name');
    }

    private function findMonitor(int $monitorId): Monitor
    {
        return $this->workspace()->monitors()->findOrFail($monitorId);
    }

    private function workspace(): Workspace
    {
        /** @var Workspace */
        return Filament::getTenant();
    }

    /**
     * "Down 41 min, 51 sec" / "Up 37 min, 2 sec" line shown under the monitor name.
     */
    public static function statusDurationLabel(Monitor $monitor): string
    {
        if ($monitor->status === MonitorStatus::Paused || $monitor->status === MonitorStatus::Pending) {
            return $monitor->status->label();
        }

        $since = $monitor->last_status_changed_at ?? $monitor->created_at;

        return $monitor->status->label().' '.$since->diffForHumans(now(), CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 2);
    }

    public static function formatSeconds(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return $hours > 0 ? "{$hours}h, {$minutes}m" : "{$minutes}m";
    }
}
