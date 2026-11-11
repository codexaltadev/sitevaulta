<?php

namespace App\Filament\App\Resources\Monitors\Pages;

use App\Enums\MonitorIncidentStatus;
use App\Enums\MonitorStatus;
use App\Enums\MonitorType;
use App\Filament\App\Resources\Monitors\MonitorResource;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\VerifiedDomain;
use App\Models\Workspace;
use App\Services\Monitoring\HostOwnershipVerifier;
use App\Services\Monitoring\MonitorCheckRunner;
use App\Services\Monitoring\MonitorUptimeStatistics;
use App\Services\Notifications\WorkspaceAlertDispatcher;
use App\Services\Security\AuditLogService;
use App\Services\Verification\OwnershipChallengeChecker;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Livewire\Attributes\Url;

/**
 * UptimeRobot-style monitor detail: status cards, uptime periods + MTBF, response-time chart
 * with downtime bands, latest incidents, and a right rail with monitor settings.
 *
 * @property Monitor $record
 */
class ViewMonitor extends Page
{
    use InteractsWithRecord;

    protected static string $resource = MonitorResource::class;

    protected string $view = 'filament.app.resources.monitors.pages.view-monitor';

    /**
     * @var array<string, string>
     */
    public const RESPONSE_TIME_RANGES = [
        '1h' => 'Last hour',
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
    ];

    /**
     * @var array<int, string>
     */
    public const MTBF_PERIOD_OPTIONS = [
        7 => '7 days',
        30 => '30 days',
        365 => '365 days',
    ];

    #[Url]
    public string $range = '24h';

    #[Url]
    public int $mtbfDays = 7;

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->name;
    }

    public function getBreadcrumbs(): array
    {
        $parent = $this->record->parent;

        return array_filter([
            MonitorResource::getUrl('index') => 'Monitoring',
            ...($parent ? [MonitorResource::getUrl('view', ['record' => $parent]) => $parent->name] : []),
            $this->record->type->label(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('testNotification')
                ->label('Test Notification')
                ->icon(Heroicon::OutlinedBellAlert)
                ->color('gray')
                ->action(fn () => $this->sendTestNotification()),

            Action::make('checkNow')
                ->label('Check now')
                ->icon(Heroicon::OutlinedArrowPath)
                ->color('gray')
                ->visible(fn (): bool => $this->record->status !== MonitorStatus::Paused)
                ->action(fn () => $this->checkNow()),

            Action::make('acceptDnsBaseline')
                ->label('Accept current DNS')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('warning')
                ->visible(fn (): bool => $this->record->type === MonitorType::Dns)
                ->requiresConfirmation()
                ->modalDescription('Use this after an intentional DNS change. The records as they are right now become the new baseline, and the DNS incident (if any) is resolved.')
                ->action(function (): void {
                    $this->record->forceFill(['dns_baseline' => null, 'last_probe_method' => null])->saveQuietly();
                    app(MonitorCheckRunner::class)->run($this->record->refresh());
                    $this->record->refresh();

                    Notification::make()->title('DNS baseline updated')->body($this->record->last_error ?? 'The current records are now the reference.')->success()->send();
                }),

            Action::make('pause')
                ->label('Pause')
                ->icon(Heroicon::OutlinedPause)
                ->color('gray')
                ->visible(fn (): bool => $this->record->status !== MonitorStatus::Paused)
                ->action(function (): void {
                    $this->record->update(['status' => MonitorStatus::Paused]);
                    Notification::make()->title('Monitor paused')->success()->send();
                }),

            Action::make('resume')
                ->label('Resume')
                ->icon(Heroicon::OutlinedPlay)
                ->visible(fn (): bool => $this->record->status === MonitorStatus::Paused)
                ->action(function (): void {
                    $this->record->update(['status' => MonitorStatus::Pending, 'last_checked_at' => null]);
                    Notification::make()->title('Monitor resumed — it will be checked within a minute')->success()->send();
                }),

            Action::make('edit')
                ->label(fn (): string => $this->record->parent_id ? 'Edit in main monitor' : 'Edit')
                ->icon(Heroicon::OutlinedCog6Tooth)
                ->url(fn (): string => MonitorResource::getUrl('edit', ['record' => $this->record->parent_id ?? $this->record])),
        ];
    }

    public function sendTestNotification(): void
    {
        /** @var Workspace $workspace */
        $workspace = $this->record->workspace;

        WorkspaceAlertDispatcher::sendDatabaseNotificationToWorkspace(
            workspace: $workspace,
            title: "🔔 Test notification for {$this->record->name}",
            body: 'If you can read this, alerts for this monitor reach you.',
            status: 'info',
        );

        WorkspaceAlertDispatcher::dispatch($workspace, 'monitor_test', [
            'monitor_name' => $this->record->name,
            'target' => $this->record->targetLabel(),
        ]);

        Notification::make()
            ->title('Test notification sent')
            ->body('Sent to workspace members and every active alert channel.')
            ->success()
            ->send();
    }

    public function checkNow(): void
    {
        if ($this->record->status === MonitorStatus::Paused) {
            return;
        }

        $check = app(MonitorCheckRunner::class)->run($this->record);
        $this->record->refresh();

        $notification = Notification::make()->title("{$this->record->name} is {$check->status->label()}");
        $check->status === MonitorStatus::Up ? $notification->success() : $notification->danger();
        $notification->send();
    }

    /**
     * Opens (or reuses) a DNS/file verification for this monitor's host, so the token can be shown right here.
     */
    public function startOwnershipVerification(): void
    {
        $host = $this->record->ownershipHost();

        if ($host === null || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return;
        }

        $this->record->workspace->verifiedDomains()->firstOrCreate(['domain' => VerifiedDomain::normalize($host)]);

        Notification::make()->title('Verification started')->body('Add the DNS TXT record or the file below, then click "Verify now".')->success()->send();
    }

    public function verifyOwnership(): void
    {
        $verifiedDomain = $this->pendingVerification();

        if ($verifiedDomain === null) {
            return;
        }

        $verified = app(HostOwnershipVerifier::class)->verify($verifiedDomain);

        AuditLogService::log(
            event: $verified ? 'monitoring.domain.verified' : 'monitoring.domain.verification_failed',
            auditable: $verifiedDomain,
            metadata: ['domain' => $verifiedDomain->domain, 'method' => $verifiedDomain->verification_method, 'monitor_id' => $this->record->id],
            workspaceId: $verifiedDomain->workspace_id,
        );

        $notification = Notification::make()->title($verified ? "{$verifiedDomain->domain} is verified" : 'Verification failed');
        $verified
            ? $notification->success()->body('Ownership is proven — port checks are now allowed on this host.')
            : $notification->danger()->body($verifiedDomain->last_error.' DNS changes can take a few minutes to appear.');
        $notification->send();
    }

    private function pendingVerification(): ?VerifiedDomain
    {
        $host = $this->record->ownershipHost();

        return $host === null ? null : $this->record->workspace->verifiedDomains()
            ->where('domain', VerifiedDomain::normalize($host))
            ->whereNull('verified_at')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $statistics = app(MonitorUptimeStatistics::class);
        $monitor = $this->record;
        $range = array_key_exists($this->range, self::RESPONSE_TIME_RANGES) ? $this->range : '24h';
        $mtbfDays = array_key_exists($this->mtbfDays, self::MTBF_PERIOD_OPTIONS) ? $this->mtbfDays : 7;

        $workspace = $monitor->workspace;
        $monitor->loadMissing('maintenanceWindows');

        return [
            'monitor' => $monitor,
            'statusPages' => $monitor->statusPages()->get(['status_pages.id', 'status_pages.name', 'status_pages.slug']),
            'underMaintenance' => $monitor->isUnderMaintenance(),
            'nextMaintenanceStart' => $monitor->nextMaintenanceStart(),
            'last24Hours' => $statistics->hourlyBarsWithUptime([$monitor->id])[$monitor->id],
            'periods' => [
                '24h' => $statistics->monitorPeriodSummary($monitor, 1),
                '7d' => $statistics->monitorPeriodSummary($monitor, 7),
                '30d' => $statistics->monitorPeriodSummary($monitor, 30),
                '365d' => $statistics->monitorPeriodSummary($monitor, 365),
            ],
            'mtbf' => $statistics->monitorPeriodSummary($monitor, $mtbfDays)['mtbf_seconds'],
            'series' => $statistics->responseTimeSeries($monitor, $range),
            'ongoingIncident' => $monitor->incidents()->where('status', MonitorIncidentStatus::Ongoing)->latest('started_at')->first(),
            // When the monitor is younger than a period, the uptime % only covers the time since its first check.
            'monitoringSince' => ($firstCheck = $monitor->checks()->min('checked_at')) !== null
                ? CarbonImmutable::parse($firstCheck, 'UTC')
                : null,
            'checksLast24Hours' => $monitor->checks()->where('checked_at', '>=', now()->utc()->subDay())->count(),
            'childChecks' => $monitor->children()->orderBy('type')->get(),
            // The parent's incidents and its extra checks' incidents, reported together.
            'latestIncidents' => MonitorIncident::query()
                ->whereIn('monitor_id', $monitor->children()->pluck('id')->push($monitor->id))
                ->with('monitor:id,name,type,parent_id')
                ->latest('started_at')
                ->limit(10)
                ->get(),
            'notifiedMembers' => $workspace?->members()->get(['users.id', 'users.name']) ?? collect(),
            'activeAlertChannelCount' => $workspace?->notificationChannels()->where('is_active', true)->count() ?? 0,
            'ownershipHost' => $ownershipHost = $monitor->ownershipHost(),
            'ownedVia' => $ownershipHost !== null && $workspace !== null
                ? (app(HostOwnershipVerifier::class)->ownedHostLabels($workspace, [$ownershipHost])[$ownershipHost] ?? null)
                : null,
            'pendingVerification' => $pendingVerification = $this->pendingVerification(),
            'copyables' => $pendingVerification === null ? [] : [
                'dns-name' => $pendingVerification->dnsRecordName(),
                'dns-value' => $pendingVerification->token,
                'file-name' => ltrim(OwnershipChallengeChecker::ROOT_FILE_PATH, '/'),
                'file-content' => $pendingVerification->token,
                'meta' => $pendingVerification->metaTag(),
            ],
            'fileUrls' => $pendingVerification === null ? [] : array_map(
                fn (string $path): string => "https://{$pendingVerification->domain}{$path}",
                OwnershipChallengeChecker::FILE_PATHS,
            ),
            'responseTimeRanges' => self::RESPONSE_TIME_RANGES,
            'mtbfPeriodOptions' => self::MTBF_PERIOD_OPTIONS,
        ];
    }

    public static function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return match (true) {
            $days > 0 => "{$days}d {$hours}h {$minutes}m",
            $hours > 0 => "{$hours}h {$minutes}m",
            default => "{$minutes}m ".($seconds % 60).'s',
        };
    }

    /**
     * Fill and tooltip for one hourly bar, drawn as a mini timeline of that hour: top = :00, bottom = :59.
     * Each outage is painted red exactly where it happened (at least a thin sliver so short blips stay
     * visible), the rest is green, and the not-yet-reached part of the current hour is faint.
     *
     * @param  array{down_seconds: int, up_percent: float|null, segments?: list<array{int, int}>, elapsed_seconds?: int}  $hour
     * @return array{style: string, label: string}
     */
    public static function hourBar(string $state, array $hour, ?CarbonInterface $hourStart = null): array
    {
        if ($state === 'none') {
            return ['style' => '', 'label' => 'Not monitored yet / no checks'];
        }

        if ($state !== 'down') {
            return ['style' => '', 'label' => 'Up · 100%'];
        }

        $segments = $hour['segments'] ?? [];
        $elapsedPercent = round(min(3600, $hour['elapsed_seconds'] ?? 3600) / 36, 2);
        $stops = [];
        $cursor = 0.0;

        foreach ($segments as [$from, $to]) {
            $start = max($cursor, round($from / 36, 2));
            $end = max($start + 4, round($to / 36, 2)); // keep very short outages visible
            $end = min(100, $end);

            if ($start > $cursor) {
                $stops[] = "var(--sv-success) {$cursor}% {$start}%";
            }

            $stops[] = "var(--sv-danger) {$start}% {$end}%";
            $cursor = $end;
        }

        // Downtime seen in checks but without an incident (rare): fall back to a sliver at the end.
        if ($segments === []) {
            $stops[] = 'var(--sv-success) 0% 88%';
            $stops[] = 'var(--sv-danger) 88% 100%';
            $cursor = 100;
        }

        if ($cursor < $elapsedPercent) {
            $stops[] = "var(--sv-success) {$cursor}% {$elapsedPercent}%";
            $cursor = $elapsedPercent;
        }

        if ($cursor < 100) {
            $stops[] = "var(--sv-border) {$cursor}% 100%";
        }

        $windows = $hourStart === null
            ? ''
            : ' ('.collect($segments)
                ->map(fn (array $segment): string => $hourStart->copy()->addSeconds($segment[0])->format('H:i').'–'.$hourStart->copy()->addSeconds($segment[1])->format('H:i'))
                ->implode(', ').')';

        return [
            'style' => 'background: linear-gradient(to bottom, '.implode(', ', $stops).');',
            'label' => self::formatPercent($hour['up_percent']).' up · down '.self::formatDuration(max(1, $hour['down_seconds'])).($segments === [] ? '' : $windows),
        ];
    }

    public static function formatPercent(?float $percent): string
    {
        return $percent === null ? '—' : rtrim(rtrim(number_format($percent, 3), '0'), '.').'%';
    }
}
