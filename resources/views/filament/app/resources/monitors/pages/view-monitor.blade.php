@php
    use App\Enums\MonitorIncidentStatus;
    use App\Enums\MonitorStatus;
    use App\Filament\App\Resources\MonitorIncidents\MonitorIncidentResource;
    use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;
    use Carbon\CarbonImmutable;

    $timezone = $monitor->workspace?->timezone ?: config('app.timezone');
    $statusClass = $monitor->status->value;

    // ---- Response time chart geometry (viewBox 1000 x 200) ----
    $chartWidth = 1000;
    $chartHeight = 200;
    $span = max(1, $series['to'] - $series['from']);
    $maxMs = max(100, (int) ceil(($series['maximum_ms'] ?? 0) * 1.15 / 100) * 100);
    $xFor = fn (int $at): float => round(($at - $series['from']) / $span * $chartWidth, 2);
    $yFor = fn (int $ms): float => round($chartHeight - ($ms / $maxMs) * $chartHeight, 2);

    $linePath = '';
    $areaPath = '';
    $segment = [];
    $tooltipPoints = [];

    // Each unbroken run of measured points becomes one line segment plus a soft filled area beneath it.
    $closeSegment = function () use (&$segment, &$linePath, &$areaPath, $chartHeight): void {
        if ($segment === []) {
            return;
        }

        $linePath .= 'M'.implode(' L', array_map(fn (array $p): string => "{$p[0]} {$p[1]}", $segment)).' ';
        $first = $segment[0];
        $last = $segment[array_key_last($segment)];
        $areaPath .= "M{$first[0]} {$chartHeight} L".implode(' L', array_map(fn (array $p): string => "{$p[0]} {$p[1]}", $segment))." L{$last[0]} {$chartHeight} Z ";
        $segment = [];
    };

    foreach ($series['points'] as $point) {
        $x = $xFor($point['at']);
        $time = CarbonImmutable::createFromTimestamp($point['at'], $timezone)->format('M j, H:i');

        if ($point['ms'] === null) {
            $closeSegment();
            $tooltipPoints[] = ['x' => $x / $chartWidth * 100, 'y' => 100, 'label' => "{$time} · Down", 'down' => true];

            continue;
        }

        $y = $yFor($point['ms']);
        $segment[] = [$x, $y];
        $tooltipPoints[] = ['x' => $x / $chartWidth * 100, 'y' => $y / $chartHeight * 100, 'label' => "{$time} · {$point['ms']} ms", 'down' => false];
    }

    $closeSegment();
    $chartClipId = 'sv-chart-reveal-'.$monitor->id.'-'.$range;

    $axisLabels = collect([0, 0.5, 1])->map(fn (float $fraction): string => CarbonImmutable::createFromTimestamp(
        (int) ($series['from'] + $span * $fraction),
        $timezone,
    )->format($range === '7d' ? 'M j' : 'M j, H:i'));
@endphp

<x-filament-panels::page>
    <style>
        .sv-md { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 20px; align-items: start; }
        @media (max-width: 1100px) { .sv-md { grid-template-columns: minmax(0, 1fr); } }
        .sv-md-card { background: var(--sv-surface); border: 1px solid var(--sv-border); border-radius: 12px; padding: 18px 20px; }
        .sv-md-row3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 16px; }
        @media (max-width: 760px) { .sv-md-row3 { grid-template-columns: minmax(0, 1fr); } }
        .sv-md-label { font-size: 13px; color: var(--sv-muted); margin: 0 0 6px; }
        .sv-md-value { font-size: 20px; font-weight: 700; color: var(--sv-text); margin: 0; }
        .sv-md-value.up { color: var(--sv-success); }
        .sv-md-value.down { color: var(--sv-danger); }
        .sv-md-value.paused { color: var(--sv-warning); }
        .sv-md-sub { font-size: 12px; color: var(--sv-muted); margin: 6px 0 0; }
        .sv-md-head { display: flex; align-items: center; gap: 14px; margin-bottom: 4px; }
        .sv-md-dot { width: 34px; height: 34px; border-radius: 999px; display: grid; place-items: center; color: var(--sv-bg); flex: none; }
        .sv-md-dot.up { background: var(--sv-success); }
        .sv-md-dot.down { background: var(--sv-danger); box-shadow: 0 0 0 5px color-mix(in srgb, var(--sv-danger) 22%, transparent); }
        .sv-md-dot.paused { background: var(--sv-warning); }
        .sv-md-dot.pending { background: var(--sv-muted); }
        .sv-md-bars { display: flex; gap: 3px; height: 30px; margin-top: 8px; }
        .sv-md-bars span { flex: 1; border-radius: 3px; background: var(--sv-border-strong); }
        /* Hours without checks (before monitoring began): striped and faint, clearly not "up". */
        .sv-md-bars span.none { background: repeating-linear-gradient(135deg, var(--sv-border) 0 3px, transparent 3px 6px); box-shadow: inset 0 0 0 1px var(--sv-border); opacity: .7; }
        .sv-md-bars span.up { background: var(--sv-success); }
        .sv-md-bars span.down { background: var(--sv-danger); }
        .sv-md-periods { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .sv-md-periods > div { padding: 0 16px; border-left: 1px solid var(--sv-border); }
        .sv-md-periods > div:first-child { border-left: 0; padding-left: 0; }
        @media (max-width: 760px) { .sv-md-periods { grid-template-columns: repeat(2, minmax(0, 1fr)); row-gap: 16px; } .sv-md-periods > div { border-left: 0; padding: 0; } }
        .sv-md-title { font-size: 15px; font-weight: 700; color: var(--sv-text); margin: 0; }
        .sv-md-title::after { content: '.'; color: var(--sv-primary); }
        .sv-md-select { background: var(--sv-bg); border: 1px solid var(--sv-border); color: var(--sv-text); border-radius: 8px; padding: 4px 8px; font-size: 12px; }
        .sv-md-chart { position: relative; margin-top: 14px; }
        .sv-md-chart svg { display: block; width: 100%; height: 200px; overflow: visible; }
        .sv-md-tooltip { position: absolute; top: -6px; transform: translate(-50%, -100%); background: var(--sv-text); color: var(--sv-bg); font-size: 12px; font-weight: 600; padding: 4px 8px; border-radius: 6px; white-space: nowrap; pointer-events: none; }
        .sv-md-axis { display: flex; justify-content: space-between; font-size: 11px; color: var(--sv-muted); margin-top: 6px; }
        .sv-md-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); margin-top: 18px; border-top: 1px solid var(--sv-border); padding-top: 14px; }
        .sv-md-stats strong { display: block; font-size: 17px; color: var(--sv-text); }
        .sv-md-stats small { font-size: 12px; color: var(--sv-muted); }
        .sv-md-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 12px; }
        .sv-md-table th { text-align: left; font-weight: 500; color: var(--sv-muted); font-size: 12px; padding: 8px 6px; border-bottom: 1px solid var(--sv-border); }
        .sv-md-table td { padding: 10px 6px; border-bottom: 1px solid var(--sv-border); color: var(--sv-text); }
        .sv-md-badge { font-size: 10px; font-weight: 700; text-transform: uppercase; border-radius: 4px; padding: 1px 5px; margin-right: 6px; background: color-mix(in srgb, var(--sv-danger) 18%, transparent); color: var(--sv-danger); }
        .sv-md-rail { display: grid; gap: 16px; }
        .sv-md-kv { display: flex; justify-content: space-between; gap: 10px; font-size: 13px; padding: 6px 0; border-bottom: 1px solid var(--sv-border); }
        .sv-md-kv:last-child { border-bottom: 0; }
        .sv-md-kv span:first-child { color: var(--sv-muted); }
        .sv-md-kv span:last-child { color: var(--sv-text); text-align: right; overflow-wrap: anywhere; }
        .sv-md-own { display: inline-block; font-size: 11px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; border-radius: 4px; padding: 2px 7px; border: 1px solid var(--sv-border-strong); color: var(--sv-muted); }
        .sv-md-own.is-owned { color: var(--sv-success); border-color: var(--sv-success); background: color-mix(in srgb, var(--sv-success) 10%, transparent); }
        .sv-md-own.is-pending { color: var(--sv-warning); border-color: var(--sv-warning); }
        .sv-md-code { font-size: 11px; background: var(--sv-bg); border: 1px solid var(--sv-border); border-radius: 4px; padding: 1px 4px; overflow-wrap: anywhere; }
        .sv-md-copy { font-size: 11px; font-weight: 600; color: var(--sv-primary); margin-left: 4px; }
        .sv-md-btn { display: block; width: 100%; text-align: center; margin-top: 10px; font-size: 12px; font-weight: 600; color: var(--sv-text); background: var(--sv-bg); border: 1px solid var(--sv-border); border-radius: 8px; padding: 6px; text-decoration: none; cursor: pointer; }
        .sv-md-btn.is-primary { background: var(--sv-primary); border-color: var(--sv-primary); color: var(--sv-bg); }
        .sv-md-btn:disabled { opacity: .6; cursor: wait; }
        .sv-md-avatar { width: 30px; height: 30px; border-radius: 999px; display: inline-grid; place-items: center; background: var(--sv-bg); border: 1px solid var(--sv-border); color: var(--sv-text); font-size: 12px; font-weight: 700; margin-right: 4px; }

        /* ---- Motion ---- */
        @keyframes sv-md-pulse-up { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--sv-success) 55%, transparent); } 70%, 100% { box-shadow: 0 0 0 12px transparent; } }
        @keyframes sv-md-pulse-down { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--sv-danger) 60%, transparent); } 70%, 100% { box-shadow: 0 0 0 14px transparent; } }
        @keyframes sv-md-bar-grow { from { transform: scaleY(0); opacity: 0; } to { transform: scaleY(1); opacity: 1; } }
        @keyframes sv-md-fade-up { from { transform: translateY(8px); opacity: 0; } to { transform: none; opacity: 1; } }
        @keyframes sv-md-fade { from { opacity: 0; } to { opacity: 1; } }
        .sv-md-dot.up { animation: sv-md-pulse-up 2.4s ease-out infinite; }
        .sv-md-dot.down { animation: sv-md-pulse-down 1.2s ease-out infinite; }
        /* Same motion as the monitors list: bars grow in left-to-right, the current hour "breathes" as a
           live indicator, and hovering a bar lifts and brightens it. */
        @keyframes sv-md-bar-breathe { 0%, 100% { opacity: 1; transform: scaleY(1); } 50% { opacity: .55; transform: scaleY(.82); } }
        .sv-md-bars span { transform-origin: bottom; animation: sv-md-bar-grow .5s cubic-bezier(.2,.8,.2,1) backwards; animation-delay: calc(var(--i, 0) * 22ms); transition: transform .18s ease, filter .18s ease; cursor: default; }
        .sv-md-bars span.is-current { animation: sv-md-bar-grow .5s cubic-bezier(.2,.8,.2,1) backwards, sv-md-bar-breathe 2.2s ease-in-out 1s infinite; animation-delay: calc(var(--i, 0) * 22ms), 1s; }
        .sv-md-bars span:hover { transform: scaleY(1.3); filter: brightness(1.2); animation-play-state: paused; }
        .sv-md-bars:hover span:not(:hover) { filter: saturate(1.15); }
        .sv-md-card { animation: sv-md-fade-up .4s ease-out both; }
        .sv-md-chart .sv-md-band { animation: sv-md-fade .6s ease-out .3s both; }
        .sv-md-stats strong { animation: sv-md-fade .5s ease-out .6s both; }
        @media (prefers-reduced-motion: reduce) {
            .sv-md-dot, .sv-md-bars span, .sv-md-card, .sv-md-chart .sv-md-band, .sv-md-stats strong { animation: none !important; }
        }
    </style>

    <div wire:poll.60s>
        <div class="sv-md-head">
            <span class="sv-md-dot {{ $statusClass }}">
                @if ($monitor->status === MonitorStatus::Up) ▲ @elseif ($monitor->status === MonitorStatus::Down) ▼ @elseif ($monitor->status === MonitorStatus::Paused) ❚❚ @else • @endif
            </span>
            <div style="min-width:0;">
                <div style="font-size:13px;color:var(--sv-muted);">
                    {{ $monitor->type->label() }} monitor for
                    @if ($monitor->type->usesUrl())
                        <a href="{{ $monitor->url }}" target="_blank" rel="noopener noreferrer" style="color:var(--sv-primary);">{{ $monitor->url }}</a>
                    @else
                        <span style="color:var(--sv-text);">{{ $monitor->targetLabel() }}</span>
                    @endif
                </div>
            </div>
        </div>

        <div class="sv-md" style="margin-top:16px;">
            <div style="display:grid;grid-template-columns:minmax(0, 1fr);gap:16px;min-width:0;">
                {{-- Row 1: current status / last check / last 24 hours --}}
                <div class="sv-md-row3">
                    <div class="sv-md-card">
                        <p class="sv-md-label">Current status</p>
                        <p class="sv-md-value {{ $statusClass }}">{{ $monitor->status->label() }}</p>
                        <p class="sv-md-sub">
                            @if (in_array($monitor->status, [MonitorStatus::Up, MonitorStatus::Down], true))
                                Currently {{ strtolower($monitor->status->label()) }} for
                                {{ ViewMonitor::formatDuration((int) ($monitor->last_status_changed_at ?? $monitor->created_at)->diffInSeconds(now())) }}
                            @elseif ($monitor->status === MonitorStatus::Paused)
                                Checks are paused
                            @else
                                Waiting for the first check
                            @endif
                        </p>
                        @if ($ongoingIncident)
                            <p class="sv-md-sub" style="color:var(--sv-danger);">
                                {{ $ongoingIncident->root_cause }} ·
                                <a href="{{ MonitorIncidentResource::getUrl('view', ['record' => $ongoingIncident]) }}" style="color:var(--sv-danger);font-weight:600;">View incident</a>
                            </p>
                        @endif
                    </div>

                    <div class="sv-md-card">
                        <p class="sv-md-label">Last check</p>
                        <p class="sv-md-value">{{ $monitor->last_checked_at?->diffForHumans(short: true) ?? 'Never' }}</p>
                        <p class="sv-md-sub">Checked every {{ $monitor->intervalLabel() }}</p>
                    </div>

                    <div class="sv-md-card">
                        <div style="display:flex;justify-content:space-between;">
                            <p class="sv-md-label">Last 24 hours</p>
                            <p class="sv-md-label" style="color:var(--sv-text);">{{ ViewMonitor::formatPercent($last24Hours['uptime_percent']) }}</p>
                        </div>
                        <div class="sv-md-bars" role="img" aria-label="Uptime for each of the last 24 hours">
                            @foreach ($last24Hours['bars'] as $bar)
                                @php
                                    $hourStart = now()->startOfHour()->subHours(count($last24Hours['bars']) - 1 - $loop->index)->setTimezone($timezone);
                                @endphp
                                @php
                                    $barView = ViewMonitor::hourBar($bar, $last24Hours['hours'][$loop->index], $hourStart);
                                @endphp
                                <span @class([$bar, 'is-current' => $loop->last]) style="--i: {{ $loop->index }}; {{ $barView['style'] }}"
                                      title="{{ $hourStart->format('M j, H:00') }} · {{ $barView['label'] }}"></span>
                            @endforeach
                        </div>
                        <p class="sv-md-sub">
                            {{ $periods['24h']['incidents'] }} {{ str('incident')->plural($periods['24h']['incidents']) }},
                            {{ ViewMonitor::formatDuration($periods['24h']['downtime_seconds']) }} down
                        </p>
                        @if ($monitoringSince && $monitoringSince->gt(now()->subDay()))
                            <p class="sv-md-sub" style="color:var(--sv-warning);">
                                Monitoring for {{ ViewMonitor::formatDuration((int) $monitoringSince->diffInSeconds(now())) }} · {{ $checksLast24Hours }} {{ str('check')->plural($checksLast24Hours) }} — striped bars are hours before monitoring started
                            </p>
                        @endif
                    </div>
                </div>

                @if ($monitor->heartbeatUrl())
                    <div class="sv-md-card" x-data>
                        <h3 class="sv-md-title">Heartbeat URL</h3>
                        <p class="sv-md-sub" style="margin-bottom:10px;">
                            Call this URL at the end of your cron job, queue worker or script.
                            We alert if no call arrives within {{ $monitor->intervalLabel() }} + {{ intdiv($monitor->heartbeat_grace_seconds, 60) }} min grace.
                            Last heartbeat: <strong style="color:var(--sv-text);">{{ $monitor->last_heartbeat_at?->diffForHumans() ?? 'never' }}</strong>
                        </p>
                        <div style="position:relative;background:var(--sv-bg);border:1px solid var(--sv-border);border-radius:8px;padding:10px 70px 10px 12px;font-family:ui-monospace,monospace;font-size:12px;color:var(--sv-text);overflow-wrap:anywhere;">
                            <span x-ref="cron">curl -fsS --retry 3 {{ $monitor->heartbeatUrl() }} &gt;/dev/null</span>
                            <button type="button" @click="navigator.clipboard.writeText($refs.cron.textContent)"
                                    style="position:absolute;top:8px;right:8px;font-size:11px;color:var(--sv-muted);border:1px solid var(--sv-border);border-radius:6px;padding:2px 8px;background:var(--sv-surface);">Copy</button>
                        </div>
                        <p class="sv-md-sub">Example crontab: <code>0 * * * * /path/to/backup.sh &amp;&amp; curl -fsS --retry 3 {{ $monitor->heartbeatUrl() }}</code></p>
                    </div>
                @endif

                @if ($childChecks->isNotEmpty())
                    <div class="sv-md-card">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                            <h3 class="sv-md-title">Checks for this target</h3>
                            <a href="{{ \App\Filament\App\Resources\Monitors\MonitorResource::getUrl('edit', ['record' => $monitor]) }}" style="font-size:12px;color:var(--sv-primary);">Add or remove checks</a>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin-top:12px;">
                            @foreach ($childChecks as $child)
                                @php
                                    $childColor = match (true) {
                                        $child->status === MonitorStatus::Down => 'var(--sv-danger)',
                                        $child->isInconclusive(), $child->status === MonitorStatus::Paused => 'var(--sv-warning)',
                                        $child->status === MonitorStatus::Up => 'var(--sv-success)',
                                        default => 'var(--sv-muted)',
                                    };
                                @endphp
                                <a href="{{ \App\Filament\App\Resources\Monitors\MonitorResource::getUrl('view', ['record' => $child]) }}"
                                   style="display:block;text-decoration:none;background:var(--sv-bg);border:1px solid var(--sv-border);border-left:4px solid {{ $childColor }};border-radius:10px;padding:12px 14px;">
                                    <div style="font-size:12px;color:var(--sv-muted);">{{ $child->type->label() }}</div>
                                    <div style="font-size:18px;font-weight:700;color:{{ $childColor }};margin-top:2px;">{{ $child->headlineMetric() }}</div>
                                    <div style="font-size:12px;color:var(--sv-muted);margin-top:4px;">
                                        @if ($child->expiryIsUnknown())
                                            Registry publishes no expiry date — add it in Edit
                                        @elseif ($child->last_probe_method === 'dns:misconfigured')
                                            No {{ $child->dns_record_type }} record exists — check the record type in Edit
                                        @elseif ($child->last_probe_method === 'dns:inconclusive')
                                            DNS resolver did not answer — retrying
                                        @elseif ($child->type->isExpiryCheck() && $child->expires_at)
                                            Expires {{ $child->expires_at->setTimezone($timezone)->format('M j, Y') }}{{ $child->last_probe_method === 'manual' ? ' (manual)' : '' }}
                                        @elseif ($child->status === MonitorStatus::Down && $child->last_error)
                                            {{ \Illuminate\Support\Str::limit($child->last_error, 60) }}
                                        @else
                                            {{ $child->targetLabel() }}
                                        @endif
                                    </div>
                                    <div style="font-size:11px;color:var(--sv-muted);margin-top:4px;">Checked {{ $child->last_checked_at?->diffForHumans() ?? 'not yet' }}</div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Row 2: uptime periods + MTBF --}}
                <div class="sv-md-card">
                    <div class="sv-md-periods">
                        @foreach (['7d' => 'Last 7 days', '30d' => 'Last 30 days', '365d' => 'Last 365 days'] as $key => $label)
                            @php($percent = $periods[$key]['uptime_percent'])
                            <div>
                                <p class="sv-md-label">{{ $label }}</p>
                                <p class="sv-md-value {{ $percent !== null && $percent < 99 ? 'down' : 'up' }}">{{ ViewMonitor::formatPercent($percent) }}</p>
                                <p class="sv-md-sub">
                                    {{ $periods[$key]['incidents'] }} {{ str('incident')->plural($periods[$key]['incidents']) }},
                                    {{ ViewMonitor::formatDuration($periods[$key]['downtime_seconds']) }} down
                                </p>
                                @if ($percent !== null && $monitoringSince && $monitoringSince->gt(now()->subDays(['7d' => 7, '30d' => 30, '365d' => 365][$key])))
                                    <p class="sv-md-sub" style="font-size:11px;">since {{ $monitoringSince->setTimezone($timezone)->format('M j, H:i') }}</p>
                                @endif
                            </div>
                        @endforeach
                        <div>
                            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                <p class="sv-md-label" style="margin:0;" title="Mean time between failures">MTBF</p>
                                <select wire:model.live="mtbfDays" class="sv-md-select" aria-label="MTBF period">
                                    @foreach ($mtbfPeriodOptions as $days => $label)
                                        <option value="{{ $days }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <p class="sv-md-value up" style="margin-top:6px;">{{ $mtbf !== null ? ViewMonitor::formatDuration($mtbf) : '—' }}</p>
                            <p class="sv-md-sub">{{ $mtbf === null ? 'No incidents in this period' : 'Average uptime between incidents' }}</p>
                        </div>
                    </div>
                </div>

                {{-- Response time chart --}}
                <div class="sv-md-card">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
                        <h3 class="sv-md-title">Response time</h3>
                        <select wire:model.live="range" class="sv-md-select" aria-label="Response time range">
                            @foreach ($responseTimeRanges as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    @if (count($series['points']) === 0)
                        <p class="sv-md-sub" style="padding:40px 0;text-align:center;">No checks in this period yet.</p>
                    @else
                        <div class="sv-md-chart"
                             x-data="{ points: @js($tooltipPoints), active: null }"
                             @mousemove="const r = $el.getBoundingClientRect(); const pct = (($event.clientX - r.left) / r.width) * 100; active = points.reduce((best, p) => best === null || Math.abs(p.x - pct) < Math.abs(best.x - pct) ? p : best, null)"
                             @mouseleave="active = null">
                            <svg viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" preserveAspectRatio="none" role="img"
                                 aria-label="Response time chart: average {{ $series['average_ms'] ?? 'n/a' }} ms, minimum {{ $series['minimum_ms'] ?? 'n/a' }} ms, maximum {{ $series['maximum_ms'] ?? 'n/a' }} ms">
                                <defs>
                                    {{-- Reveal the line left-to-right on first render (SMIL; skipped when reduced motion is preferred). --}}
                                    <clipPath id="{{ $chartClipId }}">
                                        <rect x="0" y="-10" height="{{ $chartHeight + 20 }}" width="{{ $chartWidth }}">
                                            <animate attributeName="width" from="0" to="{{ $chartWidth }}" dur="1.1s" fill="freeze"
                                                     calcMode="spline" keySplines="0.2 0.8 0.2 1" keyTimes="0;1"
                                                     x-init="if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { $el.remove() }"></animate>
                                        </rect>
                                    </clipPath>
                                </defs>
                                @foreach ($series['downtime'] as $band)
                                    <rect class="sv-md-band" x="{{ $xFor($band['from']) }}" y="0" width="{{ max(2, $xFor($band['to']) - $xFor($band['from'])) }}" height="{{ $chartHeight }}"
                                          fill="var(--sv-danger)" fill-opacity="0.16"></rect>
                                @endforeach
                                @foreach ([0, 0.5, 1] as $fraction)
                                    <line x1="0" x2="{{ $chartWidth }}" y1="{{ $chartHeight * $fraction }}" y2="{{ $chartHeight * $fraction }}"
                                          stroke="var(--sv-border)" stroke-width="1" vector-effect="non-scaling-stroke"></line>
                                @endforeach
                                <g clip-path="url(#{{ $chartClipId }})">
                                    <path d="{{ trim($areaPath) }}" fill="var(--sv-primary)" fill-opacity="0.12" stroke="none"></path>
                                    <path d="{{ trim($linePath) }}" fill="none" stroke="var(--sv-primary)" stroke-width="2"
                                          stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke"></path>
                                </g>
                            </svg>
                            <div style="position:absolute;inset:0;pointer-events:none;font-size:11px;color:var(--sv-muted);">
                                <span style="position:absolute;left:4px;top:2px;">{{ $maxMs }} ms</span>
                                <span style="position:absolute;left:4px;top:calc(50% + 2px);">{{ intdiv($maxMs, 2) }} ms</span>
                            </div>
                            <template x-if="active">
                                <div>
                                    <div :style="`position:absolute;top:0;bottom:0;left:${active.x}%;width:1px;background:var(--sv-muted);pointer-events:none;`"></div>
                                    <div x-show="! active.down" :style="`position:absolute;left:${active.x}%;top:${active.y}%;width:9px;height:9px;margin:-4.5px 0 0 -4.5px;border-radius:999px;background:var(--sv-primary);box-shadow:0 0 0 2px var(--sv-surface);pointer-events:none;`"></div>
                                    <div class="sv-md-tooltip" :style="`left:${active.x}%;`" x-text="active.label"></div>
                                </div>
                            </template>
                        </div>
                        <div class="sv-md-axis">
                            @foreach ($axisLabels as $label)
                                <span>{{ $label }}</span>
                            @endforeach
                        </div>
                        @if (count($series['downtime']) > 0)
                            <p class="sv-md-sub"><span style="display:inline-block;width:10px;height:10px;border-radius:2px;background:var(--sv-danger);opacity:.35;vertical-align:-1px;"></span> Shaded areas = downtime</p>
                        @endif
                    @endif

                    <div class="sv-md-stats">
                        <div><strong>{{ $series['average_ms'] !== null ? $series['average_ms'].' ms' : 'N/A' }}</strong><small>Average</small></div>
                        <div><strong>{{ $series['minimum_ms'] !== null ? $series['minimum_ms'].' ms' : 'N/A' }}</strong><small>Minimum</small></div>
                        <div><strong>{{ $series['maximum_ms'] !== null ? $series['maximum_ms'].' ms' : 'N/A' }}</strong><small>Maximum</small></div>
                    </div>
                </div>

                {{-- Latest incidents --}}
                <div class="sv-md-card">
                    <h3 class="sv-md-title">Latest incidents</h3>
                    @if ($latestIncidents->isEmpty())
                        <p style="text-align:center;padding:28px 0 12px;font-size:16px;font-weight:600;color:var(--sv-text);">👍 Good job, no incidents.</p>
                    @else
                        <div style="overflow-x:auto;">
                            <table class="sv-md-table">
                                <thead>
                                    <tr><th>Status</th><th>Root cause</th><th>Started</th><th>Duration</th></tr>
                                </thead>
                                <tbody>
                                    @foreach ($latestIncidents as $incident)
                                        @php($isOngoing = $incident->status === MonitorIncidentStatus::Ongoing)
                                        <tr>
                                            <td style="color:{{ $isOngoing ? 'var(--sv-danger)' : 'var(--sv-success)' }};font-weight:600;white-space:nowrap;">
                                                {{ $isOngoing ? '●' : '✓' }} {{ $incident->status->label() }}
                                            </td>
                                            <td>
                                                <a href="{{ MonitorIncidentResource::getUrl('view', ['record' => $incident]) }}" style="color:inherit;text-decoration:none;">
                                                    <span class="sv-md-badge">{{ $incident->root_cause_type }}</span>{{ $incident->root_cause }}
                                                    @if ($incident->monitor_id !== $monitor->id)
                                                        <span style="font-size:11px;color:var(--sv-muted);"> · {{ $incident->monitor->type->label() }} check</span>
                                                    @endif
                                                </a>
                                            </td>
                                            <td style="white-space:nowrap;">{{ $incident->started_at->setTimezone($timezone)->format('M j, Y, H:i') }}</td>
                                            <td style="white-space:nowrap;">{{ ViewMonitor::formatDuration($isOngoing ? (int) $incident->started_at->diffInSeconds(now()) : $incident->duration_seconds) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Right rail --}}
            <div class="sv-md-rail">
                @if ($ownershipHost)
                    <div class="sv-md-card" id="ownership">
                        <h3 class="sv-md-title" style="margin-bottom:10px;">Ownership</h3>
                        @if ($ownedVia)
                            <span class="sv-md-own is-owned">✓ Verified owner</span>
                            <p class="sv-md-sub">{{ $ownershipHost }} — proven via {{ $ownedVia }}. Port checks are allowed.</p>
                        @elseif (filter_var($ownershipHost, FILTER_VALIDATE_IP) !== false)
                            <span class="sv-md-own">Unverified</span>
                            <p class="sv-md-sub">An IP counts as owned when a verified domain or a connected server points to it. Verify that domain, or connect the server in Backup Management.</p>
                        @elseif ($pendingVerification)
                            <span class="sv-md-own is-pending">Verification pending</span>
                            <p class="sv-md-sub" style="margin-bottom:8px;">Prove you control <strong>{{ $ownershipHost }}</strong> with either option, then click Verify now.</p>
                            <div x-data="{
                                    copied: null,
                                    copy(key, text) {
                                        const done = () => { this.copied = key; setTimeout(() => this.copied = null, 1500); };
                                        if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done); return; }
                                        const area = document.createElement('textarea');
                                        area.value = text; area.style.position = 'fixed'; area.style.opacity = '0';
                                        document.body.appendChild(area); area.select(); document.execCommand('copy'); area.remove(); done();
                                    },
                                }">
                                <p class="sv-md-label" style="margin:10px 0 4px;font-weight:600;color:var(--sv-text);">Option 1 — DNS TXT record (recommended)</p>
                                <div class="sv-md-kv"><span>Type</span><span><code class="sv-md-code">TXT</code></span></div>
                                @foreach (['dns-name' => 'Name / Host', 'dns-value' => 'Value'] as $key => $label)
                                    <div class="sv-md-kv">
                                        <span>{{ $label }}</span>
                                        <span>
                                            <code class="sv-md-code">{{ $copyables[$key] }}</code>
                                            <button type="button" class="sv-md-copy" x-on:click="copy('{{ $key }}', @js($copyables[$key]))" x-text="copied === '{{ $key }}' ? 'Copied ✓' : 'Copy'"></button>
                                        </span>
                                    </div>
                                @endforeach
                                <p class="sv-md-sub" style="margin-top:4px;">Some DNS panels add the domain themselves — then enter only <code class="sv-md-code">_sitevaulta-challenge</code> as the name.</p>

                                <p class="sv-md-label" style="margin:12px 0 4px;font-weight:600;color:var(--sv-text);">Option 2 — verification file</p>
                                @foreach (['file-name' => 'File name', 'file-content' => 'Content'] as $key => $label)
                                    <div class="sv-md-kv">
                                        <span>{{ $label }}</span>
                                        <span>
                                            <code class="sv-md-code">{{ $copyables[$key] }}</code>
                                            <button type="button" class="sv-md-copy" x-on:click="copy('{{ $key }}', @js($copyables[$key]))" x-text="copied === '{{ $key }}' ? 'Copied ✓' : 'Copy'"></button>
                                        </span>
                                    </div>
                                @endforeach
                                <p class="sv-md-sub" style="margin-top:4px;">Put it in <strong>either</strong> place — no <code class="sv-md-code">.well-known</code> folder needed if you use the web root:</p>
                                @foreach ($fileUrls as $index => $fileUrl)
                                    <p class="sv-md-sub" style="margin-top:2px;">
                                        <code class="sv-md-code">{{ $fileUrl }}</code>
                                        <button type="button" class="sv-md-copy" x-on:click="copy('file-url-{{ $index }}', @js($fileUrl))" x-text="copied === 'file-url-{{ $index }}' ? 'Copied ✓' : 'Copy'"></button>
                                    </p>
                                @endforeach

                                <p class="sv-md-label" style="margin:12px 0 4px;font-weight:600;color:var(--sv-text);">Option 3 — HTML meta tag</p>
                                <p class="sv-md-sub" style="margin-top:0;">Paste inside the <code class="sv-md-code">&lt;head&gt;</code> of the homepage:</p>
                                <p class="sv-md-sub" style="margin-top:4px;">
                                    <code class="sv-md-code">{{ $copyables['meta'] }}</code>
                                    <button type="button" class="sv-md-copy" x-on:click="copy('meta', @js($copyables['meta']))" x-text="copied === 'meta' ? 'Copied ✓' : 'Copy'"></button>
                                </p>
                                <p class="sv-md-sub">Verify now tries all three — whichever you set up.</p>
                            </div>
                            @if ($pendingVerification->last_error)
                                <div class="sv-md-sub" style="color:var(--sv-danger);">
                                    <p style="margin:0 0 2px;font-weight:600;">Last attempt {{ $pendingVerification->last_attempted_at?->diffForHumans() }} — not found with:</p>
                                    {{ $pendingVerification->errorListHtml() }}
                                </div>
                            @endif
                            <button type="button" class="sv-md-btn is-primary" wire:click="verifyOwnership" wire:loading.attr="disabled" wire:target="verifyOwnership">
                                <span wire:loading.remove wire:target="verifyOwnership">Verify now</span>
                                <span wire:loading wire:target="verifyOwnership">Checking…</span>
                            </button>
                        @else
                            <span class="sv-md-own">Unverified</span>
                            <p class="sv-md-sub">Uptime, SSL, domain and DNS checks work without it. Proving you own <strong>{{ $ownershipHost }}</strong> unlocks port checks and shows a verified badge.</p>
                            <button type="button" class="sv-md-btn is-primary" wire:click="startOwnershipVerification">Verify ownership</button>
                        @endif
                        <a href="{{ \App\Filament\App\Resources\VerifiedDomains\VerifiedDomainResource::getUrl('index') }}" class="sv-md-btn">All verified domains</a>
                    </div>
                @endif

                <div class="sv-md-card">
                    <h3 class="sv-md-title" style="margin-bottom:10px;">Monitor details</h3>
                    <div class="sv-md-kv"><span>Type</span><span>{{ $monitor->type->label() }}</span></div>
                    <div class="sv-md-kv"><span>Target</span><span>{{ $monitor->targetLabel() }}</span></div>
                    @if ($monitor->keyword)
                        <div class="sv-md-kv"><span>Keyword</span><span>"{{ $monitor->keyword }}" {{ $monitor->keyword_should_exist ? 'must exist' : 'must not exist' }}</span></div>
                    @endif
                    @if ($monitor->type === \App\Enums\MonitorType::Dns)
                        <div class="sv-md-kv"><span>Watching</span><span>{{ implode(', ', $monitor->dnsRecordTypes()) }}</span></div>
                        @foreach ((array) $monitor->dns_baseline as $recordType => $values)
                            <div class="sv-md-kv"><span>Baseline {{ $recordType }}</span><span>{{ implode(', ', (array) $values) }}</span></div>
                        @endforeach
                    @endif
                    @if ($monitor->probeMethodLabel())
                        <div class="sv-md-kv"><span>Measured via</span><span>{{ $monitor->probeMethodLabel() }}</span></div>
                    @endif
                    @if ($monitor->type === \App\Enums\MonitorType::Domain && str_ends_with((string) $monitor->host, '.bd'))
                        <div class="sv-md-kv">
                            <span>Expiry date</span>
                            <span>
                                <a href="{{ \App\Filament\App\Resources\Monitors\Schemas\MonitorForm::BTCL_DOMAIN_LOOKUP_URL }}" target="_blank" rel="noopener noreferrer" style="color:var(--sv-primary);">Check on BTCL ↗</a>
                                · <a href="{{ \App\Filament\App\Resources\Monitors\MonitorResource::getUrl('edit', ['record' => $monitor->parent_id ?? $monitor]) }}" style="color:var(--sv-primary);">Set date</a>
                            </span>
                        </div>
                    @endif
                    <div class="sv-md-kv"><span>Interval</span><span>{{ $monitor->intervalLabel() }}</span></div>
                    <div class="sv-md-kv"><span>Timeout</span><span>{{ $monitor->timeout_seconds }} sec</span></div>
                    @if ($monitor->type->usesUrl())
                        <div class="sv-md-kv"><span>Method</span><span>{{ $monitor->http_method }}</span></div>
                    @endif
                    @if ($monitor->parent)
                        <div class="sv-md-kv"><span>Part of</span><span><a href="{{ \App\Filament\App\Resources\Monitors\MonitorResource::getUrl('view', ['record' => $monitor->parent]) }}" style="color:var(--sv-primary);">{{ $monitor->parent->name }}</a></span></div>
                    @endif
                    @if ($monitor->website)
                        <div class="sv-md-kv"><span>Backup website</span><span>{{ $monitor->website->name }}</span></div>
                    @endif
                </div>

                <div class="sv-md-card">
                    <h3 class="sv-md-title" style="margin-bottom:10px;">Next maintenance</h3>
                    @if ($underMaintenance)
                        <p style="margin:0;font-size:13px;font-weight:600;color:var(--sv-warning);">🔧 Maintenance in progress</p>
                        <p class="sv-md-sub">Checks are paused and no alerts are sent.</p>
                    @elseif ($nextMaintenanceStart)
                        <p style="margin:0;font-size:13px;color:var(--sv-text);">{{ $nextMaintenanceStart->setTimezone($timezone)->format('M j, Y, H:i') }}</p>
                        <p class="sv-md-sub">{{ $nextMaintenanceStart->diffForHumans() }}</p>
                    @else
                        <p class="sv-md-sub" style="margin-top:0;">No maintenance planned.</p>
                    @endif
                    <a href="{{ \App\Filament\App\Resources\MaintenanceWindows\MaintenanceWindowResource::getUrl($underMaintenance || $nextMaintenanceStart ? 'index' : 'create') }}"
                       style="display:block;text-align:center;margin-top:12px;font-size:12px;font-weight:600;color:var(--sv-text);background:var(--sv-bg);border:1px solid var(--sv-border);border-radius:8px;padding:6px;text-decoration:none;">
                        {{ $underMaintenance || $nextMaintenanceStart ? 'Manage maintenance' : 'Set up maintenance' }}
                    </a>
                </div>

                <div class="sv-md-card">
                    <h3 class="sv-md-title" style="margin-bottom:10px;">Appears on</h3>
                    @forelse ($statusPages as $statusPage)
                        <div class="sv-md-kv">
                            <span>{{ $statusPage->name }}</span>
                            <span><a href="{{ $statusPage->publicUrl() }}" target="_blank" rel="noopener" style="color:var(--sv-primary);">Open ↗</a></span>
                        </div>
                    @empty
                        <p class="sv-md-sub" style="margin-top:0;">Attached to no status page.</p>
                    @endforelse
                </div>

                <div class="sv-md-card">
                    <h3 class="sv-md-title" style="margin-bottom:10px;">Regions</h3>
                    <div class="sv-md-kv"><span>{{ \App\Services\Monitoring\MonitorCheckRunner::PROBE_LOCATION }}</span><span>Re-check confirms every failure</span></div>
                </div>

                <div class="sv-md-card">
                    <h3 class="sv-md-title" style="margin-bottom:10px;">To be notified</h3>
                    <div>
                        @forelse ($notifiedMembers as $member)
                            <span class="sv-md-avatar" title="{{ $member->name }}">{{ str($member->name)->substr(0, 1)->upper() }}</span>
                        @empty
                            <span class="sv-md-sub">No workspace members</span>
                        @endforelse
                    </div>
                    <p class="sv-md-sub">
                        {{ $monitor->notify_workspace_members ? '🔔 In-app bell for members' : '🔕 Members not notified in-app' }}<br>
                        @if ($monitor->alert_channel_ids)
                            + {{ count($monitor->alert_channel_ids) }} selected alert {{ str('channel')->plural(count($monitor->alert_channel_ids)) }}
                        @else
                            + all {{ $activeAlertChannelCount }} active alert {{ str('channel')->plural($activeAlertChannelCount) }}
                        @endif
                    </p>
                    <div class="sv-md-kv"><span>Down alert</span><span>{{ \App\Filament\App\Resources\Monitors\Schemas\MonitorForm::ALERT_DELAY_OPTIONS[$monitor->alert_delay_minutes] ?? "After {$monitor->alert_delay_minutes} min" }}</span></div>
                    <div class="sv-md-kv"><span>Reminders</span><span>{{ \App\Filament\App\Resources\Monitors\Schemas\MonitorForm::ALERT_REPEAT_OPTIONS[$monitor->alert_repeat_minutes] ?? "Every {$monitor->alert_repeat_minutes} min" }}</span></div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
