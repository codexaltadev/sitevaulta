@php
    use App\Enums\MonitorIncidentStatus;
    use App\Enums\MonitorStatus;
    use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;

    $banner = [
        'operational' => ['All systems operational', 'var(--sp-up)', '✓'],
        'partial_outage' => ['Some systems are down', 'var(--sp-degraded)', '!'],
        'major_outage' => ['Major outage', 'var(--sp-down)', '✕'],
        'maintenance' => ['Scheduled maintenance in progress', 'var(--sp-maintenance)', '🔧'],
    ][$overallState];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="60">
    <title>{{ $statusPage->name }} · Status</title>
    <meta name="description" content="{{ $statusPage->description ?: 'Live status of '.$statusPage->name }}">
    @include('status-pages.partials.styles')
    <style>
        .sp-banner { display: flex; align-items: center; gap: 14px; color: #fff; border-radius: 14px; padding: 18px 22px; font-size: 18px; font-weight: 700; }
        .sp-banner-icon { width: 32px; height: 32px; border-radius: 999px; background: rgba(255,255,255,.22); display: grid; place-items: center; font-size: 16px; flex: none; }
        .sp-monitor { padding: 16px 0; border-top: 1px solid var(--sp-border); }
        .sp-monitor:first-child { border-top: 0; padding-top: 0; }
        .sp-monitor-head { display: flex; justify-content: space-between; align-items: baseline; gap: 12px; }
        .sp-monitor-name { font-weight: 600; overflow-wrap: anywhere; }
        .sp-state { font-size: 13px; font-weight: 600; white-space: nowrap; }
        .sp-bars { display: flex; gap: 2px; height: 34px; margin-top: 10px; }
        .sp-bars span { flex: 1; border-radius: 2px; background: var(--sp-none); min-width: 1px; }
        .sp-bars span.up { background: var(--sp-up); }
        .sp-bars span.degraded { background: var(--sp-degraded); }
        .sp-bars span.down { background: var(--sp-down); }
        .sp-bars span:hover { opacity: .7; }
        .sp-bar-axis { display: flex; justify-content: space-between; font-size: 12px; color: var(--sp-muted); margin-top: 6px; }
        .sp-list-item { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-top: 1px solid var(--sp-border); font-size: 14px; }
        .sp-list-item:first-child { border-top: 0; padding-top: 0; }
        @media (max-width: 640px) { .sp-bars { gap: 1px; } .sp-list-item { flex-direction: column; gap: 2px; } }
    </style>
</head>
<body>
    <main class="sp-wrap">
        <header style="margin-bottom:22px;">
            <h1 class="sp-h1">{{ $statusPage->name }}</h1>
            @if ($statusPage->description)
                <p class="sp-muted" style="margin:6px 0 0;">{{ $statusPage->description }}</p>
            @endif
        </header>

        @if (session('status_page_message'))
            <div class="sp-card" role="status" style="margin-bottom:14px;border-left:4px solid var(--sp-up);font-size:14px;">{{ session('status_page_message') }}</div>
        @endif

        <div class="sp-banner" style="background:{{ $banner[1] }};" role="status">
            <span class="sp-banner-icon" aria-hidden="true">{{ $banner[2] }}</span>
            <span>{{ $banner[0] }}</span>
        </div>

        @foreach ($announcements as $announcement)
            <section class="sp-card" style="margin-top:18px;border-left:4px solid {{ ['incident' => 'var(--sp-down)', 'maintenance' => 'var(--sp-maintenance)'][$announcement->type] ?? 'var(--sp-up)' }};">
                <div class="sp-muted" style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;">
                    {{ $announcement->typeLabel() }} · {{ $announcement->updated_at->setTimezone($timezone)->format('M j, H:i T') }}
                </div>
                <h2 class="sp-h2" style="margin:4px 0 0;">{{ $announcement->title }}</h2>
                @if ($announcement->body)
                    <p style="margin:8px 0 0;white-space:pre-line;">{{ $announcement->body }}</p>
                @endif
            </section>
        @endforeach

        @if ($maintenance->isNotEmpty())
            <section class="sp-card" style="margin-top:18px;border-left:4px solid var(--sp-maintenance);">
                <h2 class="sp-h2">Scheduled maintenance</h2>
                @foreach ($maintenance as $entry)
                    <div class="sp-list-item">
                        <div>
                            <strong>{{ $entry['name'] }}</strong>
                            <div class="sp-muted" style="font-size:13px;">Affects: {{ implode(', ', $entry['monitors']) }}</div>
                        </div>
                        <div class="sp-muted" style="white-space:nowrap;">
                            @if ($entry['in_progress'])
                                <span style="color:var(--sp-maintenance);font-weight:600;">In progress</span>
                            @else
                                {{ $entry['starts_at']->setTimezone($timezone)->format('M j, H:i T') }}
                            @endif
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        <section class="sp-card" style="margin-top:18px;">
            @forelse ($monitors as $monitor)
                @php
                    $monitorUptime = $uptime[$monitor->id] ?? ['days' => [], 'uptime_percent' => null];
                    $inMaintenance = $monitor->isUnderMaintenance();
                    [$stateLabel, $stateColor] = match (true) {
                        $inMaintenance => ['Maintenance', 'var(--sp-maintenance)'],
                        $monitor->status === MonitorStatus::Down => ['Down', 'var(--sp-down)'],
                        $monitor->status === MonitorStatus::Up => ['Operational', 'var(--sp-up)'],
                        default => ['Checking…', 'var(--sp-muted)'],
                    };
                @endphp
                <div class="sp-monitor">
                    <div class="sp-monitor-head">
                        <span class="sp-monitor-name">{{ $monitor->name }}</span>
                        <span class="sp-state" style="color:{{ $stateColor }};">{{ $stateLabel }}</span>
                    </div>
                    @if ($statusPage->show_uptime_bars)
                        <div class="sp-bars" role="img" aria-label="{{ $monitor->name }}: daily uptime for the last {{ count($monitorUptime['days']) }} days">
                            @foreach ($monitorUptime['days'] as $day)
                                <span class="{{ $day['state'] }}"
                                      title="{{ \Carbon\CarbonImmutable::parse($day['date'])->format('M j, Y') }} · {{ $day['uptime_percent'] === null ? 'No data' : rtrim(rtrim(number_format($day['uptime_percent'], 2), '0'), '.').'% uptime' }}"></span>
                            @endforeach
                        </div>
                        <div class="sp-bar-axis">
                            <span>{{ count($monitorUptime['days']) }} days ago</span>
                            <span>{{ ViewMonitor::formatPercent($monitorUptime['uptime_percent']) }} uptime</span>
                            <span>Today</span>
                        </div>
                    @endif
                </div>
            @empty
                <p class="sp-muted" style="margin:0;text-align:center;">No services are listed on this page yet.</p>
            @endforelse
        </section>

        @if ($statusPage->show_incident_history)
            <section class="sp-card" style="margin-top:18px;">
                <h2 class="sp-h2">Past {{ \App\Http\Controllers\StatusPageController::INCIDENT_HISTORY_DAYS }} days</h2>
                @forelse ($incidents as $incident)
                    @php($isOngoing = $incident->status === MonitorIncidentStatus::Ongoing)
                    <div class="sp-list-item">
                        <div>
                            <strong>{{ $incident->monitor->name }}</strong>
                            <span style="color:{{ $isOngoing ? 'var(--sp-down)' : 'var(--sp-up)' }};font-size:13px;font-weight:600;margin-left:6px;">
                                {{ $isOngoing ? 'Ongoing outage' : 'Resolved' }}
                            </span>
                        </div>
                        <div class="sp-muted" style="font-size:13px;white-space:nowrap;">
                            {{ $incident->started_at->setTimezone($timezone)->format('M j, H:i T') }}
                            · {{ ViewMonitor::formatDuration($incident->duration_seconds ?? (int) $incident->started_at->diffInSeconds(now())) }}
                        </div>
                    </div>
                @empty
                    @if ($pastAnnouncements->isEmpty())
                        <p class="sp-muted" style="margin:0;">No incidents reported. 🎉</p>
                    @endif
                @endforelse
                @foreach ($pastAnnouncements as $announcement)
                    <div class="sp-list-item">
                        <div>
                            <strong>{{ $announcement->title }}</strong>
                            <span class="sp-muted" style="font-size:13px;margin-left:6px;">{{ $announcement->typeLabel() }} · resolved</span>
                        </div>
                        <div class="sp-muted" style="font-size:13px;white-space:nowrap;">{{ $announcement->resolved_at->setTimezone($timezone)->format('M j, H:i T') }}</div>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($statusPage->allow_subscriptions)
            <section class="sp-card" style="margin-top:18px;">
                <h2 class="sp-h2">Get updates by email</h2>
                <p class="sp-muted" style="margin:0 0 12px;font-size:14px;">We email you when a service goes down, comes back, or we post an update. Unsubscribe any time.</p>
                <form method="POST" action="{{ $formUrls['subscribe'] }}" style="display:flex;flex-wrap:wrap;gap:8px;">
                    @csrf
                    <label for="sp-subscribe-email" class="sp-sr-only">Email address</label>
                    <input id="sp-subscribe-email" type="email" name="email" required maxlength="191" placeholder="you@example.com" autocomplete="email"
                           style="flex:1 1 220px;padding:10px 12px;border:1px solid var(--sp-border);border-radius:8px;font-size:14px;background:var(--sp-bg);color:var(--sp-text);">
                    <button type="submit" style="padding:10px 16px;border:0;border-radius:8px;background:var(--sp-up);color:#fff;font-weight:600;font-size:14px;cursor:pointer;">Subscribe</button>
                </form>
                @error('email')
                    <p style="margin:8px 0 0;color:var(--sp-down);font-size:13px;">{{ $message }}</p>
                @enderror
            </section>
        @endif

        <footer class="sp-footer">
            Updated {{ now()->setTimezone($timezone)->format('M j, Y H:i T') }} · refreshes every minute<br>
            Powered by {{ config('app.name', 'SiteVaulta') }}
        </footer>
    </main>
</body>
</html>
