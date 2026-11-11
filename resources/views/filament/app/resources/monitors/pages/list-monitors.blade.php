@php
    use App\Enums\MonitorStatus;
    use App\Filament\App\Resources\Monitors\MonitorResource;
    use App\Filament\App\Resources\Monitors\Pages\ListMonitors;
@endphp

<x-filament-panels::page>
    <style>
        .sv-mon { display: grid; grid-template-columns: minmax(0, 1fr) 280px; gap: 20px; align-items: start; }
        @media (max-width: 1100px) { .sv-mon { grid-template-columns: minmax(0, 1fr); } }
        .sv-mon-card { background: var(--sv-surface); border: 1px solid var(--sv-border); border-radius: 12px; }
        .sv-mon-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 12px; }
        .sv-mon-toolbar .sv-grow { flex: 1 1 auto; }
        .sv-mon-input { background: var(--sv-bg); border: 1px solid var(--sv-border); color: var(--sv-text); border-radius: 8px; padding: 7px 10px; font-size: 13px; }
        .sv-mon-input:focus { outline: 2px solid var(--sv-primary); outline-offset: -1px; }
        .sv-mon-row { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-top: 1px solid var(--sv-border); }
        .sv-mon-row:first-child { border-top: 0; }
        .sv-mon-row:hover { background: var(--sv-bg); }
        .sv-mon-dot { width: 22px; height: 22px; border-radius: 999px; display: grid; place-items: center; flex: none; color: var(--sv-bg); font-size: 10px; }
        .sv-mon-dot.up { background: var(--sv-success); }
        .sv-mon-dot.down { background: var(--sv-danger); box-shadow: 0 0 0 4px color-mix(in srgb, var(--sv-danger) 22%, transparent); }
        .sv-mon-dot.paused { background: var(--sv-warning); }
        .sv-mon-dot.pending { background: var(--sv-muted); }
        .sv-mon-name { font-weight: 600; color: var(--sv-text); font-size: 14px; text-decoration: none; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block; }
        .sv-mon-name:hover { color: var(--sv-primary); }
        .sv-mon-info { flex: 1 1 auto; min-width: 0; }
        .sv-mon-history { flex: none; }
        /* Phones: name and details across the top, the 24-hour bars full width underneath. */
        @media (max-width: 640px) {
            .sv-mon-row { flex-wrap: wrap; row-gap: 8px; padding: 12px; }
            .sv-mon-dot { order: 1; }
            /* The name takes the whole first line (next to the dot and the ⋯ menu). */
            .sv-mon-info { order: 2; flex: 1 1 calc(100% - 90px); }
            .sv-mon-menu { order: 3; }
            .sv-mon-incident { order: 4; margin-left: 36px; }
            .sv-mon-interval { order: 5; margin-left: auto; }
            .sv-mon-history { order: 6; flex: 1 0 100%; }
            .sv-mon-history .sv-mon-bars { gap: 2px; }
            .sv-mon-history .sv-mon-bars span { flex: 1 1 0; width: auto; }
            .sv-mon-row .sv-mon-meta { white-space: normal; overflow: visible; line-height: 1.8; }
            .sv-mon-row .sv-mon-pill { display: inline-block; white-space: nowrap; line-height: 1.4; }
        }
        .sv-mon-title { display: flex; align-items: center; gap: 6px; min-width: 0; }
        .sv-mon-title .sv-mon-name { min-width: 0; }
        .sv-mon-visit { flex: none; display: inline-grid; place-items: center; width: 22px; height: 22px; border-radius: 6px; color: var(--sv-muted); }
        .sv-mon-visit:hover, .sv-mon-visit:focus-visible { color: var(--sv-primary); background: var(--sv-bg); }
        .sv-mon-visit-icon { width: 14px; height: 14px; }
        .sv-mon-meta { font-size: 12px; color: var(--sv-muted); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .sv-mon-pill.sv-mon-unverified { text-decoration: none; border-style: dashed; }
        .sv-mon-pill.sv-mon-unverified:hover { color: var(--sv-primary); border-color: var(--sv-primary); }
        .sv-mon-pill { font-size: 10px; font-weight: 700; letter-spacing: .04em; border: 1px solid var(--sv-border-strong); color: var(--sv-muted); border-radius: 4px; padding: 0 5px; margin-right: 6px; }
        .sv-mon-meta .is-down { color: var(--sv-danger); }
        .sv-mon-interval { font-size: 12px; color: var(--sv-muted); white-space: nowrap; flex: none; }
        .sv-mon-bars { display: flex; gap: 2px; height: 18px; align-items: stretch; }
        .sv-mon-bars span { width: 4px; border-radius: 2px; background: var(--sv-border-strong); }
        .sv-mon-bars span.up { background: var(--sv-success); }
        .sv-mon-bars span.none { background: repeating-linear-gradient(135deg, var(--sv-border) 0 2px, transparent 2px 4px); box-shadow: inset 0 0 0 1px var(--sv-border); opacity: .7; }
        .sv-mon-bars span.down { background: var(--sv-danger); }
        .sv-mon-uptime { font-size: 11px; color: var(--sv-muted); text-align: right; margin-top: 3px; }
        .sv-mon-menu { position: relative; flex: none; }
        .sv-mon-menu-list { position: absolute; right: 0; top: 28px; z-index: 20; min-width: 150px; padding: 4px; background: var(--sv-surface); border: 1px solid var(--sv-border); border-radius: 10px; box-shadow: 0 10px 25px -10px rgba(0,0,0,.35); }
        .sv-mon-menu-list button, .sv-mon-menu-list a { display: block; width: 100%; text-align: left; padding: 7px 10px; font-size: 13px; color: var(--sv-text); border-radius: 6px; text-decoration: none; }
        .sv-mon-menu-list button:hover, .sv-mon-menu-list a:hover { background: var(--sv-bg); }
        .sv-mon-menu-list .danger { color: var(--sv-danger); }
        .sv-mon-icon-btn { color: var(--sv-muted); padding: 4px 8px; border-radius: 6px; }
        .sv-mon-icon-btn:hover { background: var(--sv-bg); color: var(--sv-text); }
        .sv-mon-rail h3 { font-weight: 700; color: var(--sv-text); font-size: 15px; margin: 0 0 14px; }
        .sv-mon-rail h3::after { content: '.'; color: var(--sv-primary); }
        .sv-mon-big-dot { width: 46px; height: 46px; border-radius: 999px; margin: 0 auto 14px; display: grid; place-items: center; color: var(--sv-bg); font-size: 16px; }
        .sv-mon-counts { display: grid; grid-template-columns: repeat(3, 1fr); text-align: center; }
        .sv-mon-counts strong { display: block; font-size: 18px; color: var(--sv-text); }
        .sv-mon-counts small, .sv-mon-kpis small { font-size: 12px; color: var(--sv-muted); }
        .sv-mon-kpis { display: grid; grid-template-columns: 1fr 1fr; gap: 16px 12px; }
        .sv-mon-kpis strong { display: block; font-size: 18px; color: var(--sv-text); }
        .sv-mon-empty { padding: 48px 24px; text-align: center; color: var(--sv-muted); }

        /* ---- Motion: living status dots + bars that grow in, like UptimeRobot ---- */
        @keyframes sv-pulse-up { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--sv-success) 55%, transparent); } 70%, 100% { box-shadow: 0 0 0 9px transparent; } }
        @keyframes sv-pulse-down { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--sv-danger) 60%, transparent); } 70%, 100% { box-shadow: 0 0 0 11px transparent; } }
        @keyframes sv-bar-grow { from { transform: scaleY(0); opacity: 0; } to { transform: scaleY(1); opacity: 1; } }
        @keyframes sv-fade-up { from { transform: translateY(6px); opacity: 0; } to { transform: none; opacity: 1; } }
        .sv-mon-dot.up { animation: sv-pulse-up 2.4s ease-out infinite; }
        .sv-mon-dot.down { animation: sv-pulse-down 1.2s ease-out infinite; }
        /* "backwards" fill: once an entrance animation ends it stops applying, so rows do not keep a
           stacking context that would hide an open actions menu behind the next row. */
        @keyframes sv-bar-breathe { 0%, 100% { opacity: 1; transform: scaleY(1); } 50% { opacity: .55; transform: scaleY(.82); } }
        .sv-mon-bars span { transform-origin: bottom; animation: sv-bar-grow .5s cubic-bezier(.2,.8,.2,1) backwards; animation-delay: calc(var(--i, 0) * 22ms); transition: transform .18s ease, filter .18s ease; cursor: default; }
        .sv-mon-bars span.is-current { animation: sv-bar-grow .5s cubic-bezier(.2,.8,.2,1) backwards, sv-bar-breathe 2.2s ease-in-out 1s infinite; animation-delay: calc(var(--i, 0) * 22ms), 1s; }
        .sv-mon-bars span:hover { transform: scaleY(1.3); filter: brightness(1.2); animation-play-state: paused; }
        .sv-mon-row:hover .sv-mon-bars span:not(:hover) { filter: saturate(1.15); }
        .sv-mon-row { animation: sv-fade-up .35s ease-out backwards; transition: background-color .15s ease; }
        .sv-mon-rail .sv-mon-card { animation: sv-fade-up .4s ease-out backwards; }
        @media (prefers-reduced-motion: reduce) {
            .sv-mon-dot, .sv-mon-bars span, .sv-mon-row, .sv-mon-rail .sv-mon-card { animation: none !important; }
        }
    </style>

    <div class="sv-mon" wire:poll.60s>
        {{-- ===== Monitor feed ===== --}}
        <div>
            <div class="sv-mon-toolbar">
                <div class="sv-grow" style="font-size:13px;color:var(--sv-muted);">
                    {{ $monitors->count() }} {{ str('monitor')->plural($monitors->count()) }}
                </div>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Search by name or URL" class="sv-mon-input" style="min-width:220px;" aria-label="Search monitors">
                <select wire:model.live="statusFilter" class="sv-mon-input" aria-label="Filter by status">
                    @foreach ($statusFilterOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
                <select wire:model.live="sort" class="sv-mon-input" aria-label="Sort monitors">
                    @foreach ($sortOptions as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div class="sv-mon-card">
                @forelse ($monitors as $monitor)
                    @php
                        $stats = $monitorStats[$monitor->id] ?? ['bars' => [], 'uptime_percent' => null];
                        $statusClass = $monitor->status->value;
                    @endphp
                    <div class="sv-mon-row" wire:key="monitor-{{ $monitor->id }}">
                        <span class="sv-mon-dot {{ $statusClass }}" title="{{ $monitor->status->label() }}">
                            @if ($monitor->status === MonitorStatus::Up) ▲ @elseif ($monitor->status === MonitorStatus::Down) ▼ @elseif ($monitor->status === MonitorStatus::Paused) ❚❚ @else • @endif
                        </span>

                        <div class="sv-mon-info">
                            <div class="sv-mon-title">
                                <a href="{{ MonitorResource::getUrl('view', ['record' => $monitor]) }}" class="sv-mon-name">{{ $monitor->name }}</a>
                                @if ($siteUrl = $monitor->siteUrl())
                                    <a href="{{ $siteUrl }}" target="_blank" rel="noopener noreferrer nofollow" class="sv-mon-visit" title="Open {{ $siteUrl }} in a new tab" aria-label="Open {{ $monitor->name }} in a new tab">
                                        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="sv-mon-visit-icon" />
                                    </a>
                                @endif
                            </div>
                            <div class="sv-mon-meta">
                                <span class="sv-mon-pill">{{ $monitor->type->shortLabel() }}</span>
                                @if (isset($ownedHosts[$monitor->target_host]))
                                    <a href="{{ MonitorResource::getUrl('view', ['record' => $monitor]) }}#ownership" class="sv-mon-pill" style="color:var(--sv-success);border-color:var(--sv-success);text-decoration:none;" title="Ownership proven via {{ $ownedHosts[$monitor->target_host] }}">✓ VERIFIED OWNER</a>
                                @elseif ($monitor->target_host)
                                    <a href="{{ MonitorResource::getUrl('view', ['record' => $monitor]) }}#ownership" class="sv-mon-pill sv-mon-unverified" title="Ownership not proven — click to see how to verify {{ $monitor->target_host }}">UNVERIFIED</a>
                                @endif
                                @if ($monitor->isUnderMaintenance())
                                    <span class="sv-mon-pill" style="color:var(--sv-warning);border-color:var(--sv-warning);">🔧 MAINTENANCE</span>
                                @endif
                                @if ($monitor->slow_alerted_at && $monitor->status === MonitorStatus::Up)
                                    <span class="sv-mon-pill" style="color:var(--sv-warning);border-color:var(--sv-warning);" title="Slower than {{ $monitor->slow_threshold_ms }} ms since {{ $monitor->slow_alerted_at->diffForHumans() }}">🐢 SLOW {{ $monitor->last_response_time_ms }}ms</span>
                                @endif
                                @if ($monitor->last_regional_note && $monitor->status === MonitorStatus::Up)
                                    <span class="sv-mon-pill" style="color:var(--sv-warning);border-color:var(--sv-warning);" title="{{ $monitor->last_regional_note }}">⚠ REGIONAL</span>
                                @endif
                                <span @class(['is-down' => $monitor->status === MonitorStatus::Down])>{{ ListMonitors::statusDurationLabel($monitor) }}</span>
                                @if ($monitor->status === MonitorStatus::Down && $monitor->last_error)
                                    <span> | {{ $monitor->last_error }}</span>
                                @endif
                                @foreach ($monitor->children as $child)
                                    <span class="sv-mon-pill" title="{{ $child->type->label() }}{{ $child->last_error ? ': '.$child->last_error : '' }}"
                                          style="margin-left:6px;{{ match (true) {
                                              $child->status === MonitorStatus::Down => 'color:var(--sv-danger);border-color:var(--sv-danger);',
                                              $child->isInconclusive() => 'color:var(--sv-warning);border-color:var(--sv-warning);',
                                              $child->status === MonitorStatus::Up => 'color:var(--sv-success);border-color:var(--sv-success);',
                                              default => '',
                                          } }}">
                                        {{ $child->type->shortLabel() }} {{ $child->headlineMetric() }}
                                    </span>
                                @endforeach
                            </div>
                        </div>

                        @if ($monitor->ongoingIncident)
                            <a href="{{ \App\Filament\App\Resources\MonitorIncidents\MonitorIncidentResource::getUrl('view', ['record' => $monitor->ongoingIncident]) }}"
                               class="sv-mon-incident"
                               style="flex:none;font-size:12px;font-weight:600;color:var(--sv-text);background:var(--sv-bg);border:1px solid var(--sv-border);border-radius:6px;padding:3px 9px;text-decoration:none;white-space:nowrap;">
                                View incident
                            </a>
                        @endif

                        <span class="sv-mon-interval" title="Check interval">⟳ {{ $monitor->intervalLabel() }}</span>

                        <div class="sv-mon-history">
                            <div class="sv-mon-bars" title="Last 24 hours, one bar per hour">
                                @foreach ($stats['bars'] as $bar)
                                    @php
                                        $barHourStart = now()->startOfHour()->subHours(count($stats['bars']) - 1 - $loop->index)->setTimezone($monitor->workspace?->timezone ?: config('app.timezone'));
                                        $barView = \App\Filament\App\Resources\Monitors\Pages\ViewMonitor::hourBar($bar, $stats['hours'][$loop->index] ?? ['down_seconds' => 0, 'up_percent' => null], $barHourStart);
                                    @endphp
                                    <span @class([$bar, 'is-current' => $loop->last]) style="--i: {{ $loop->index }}; {{ $barView['style'] }}"
                                          title="{{ now()->subHours(count($stats['bars']) - 1 - $loop->index)->setTimezone($monitor->workspace?->timezone ?: config('app.timezone'))->format('M j, H:00') }} · {{ $barView['label'] }}"></span>
                                @endforeach
                            </div>
                            <div class="sv-mon-uptime">
                                {{ $stats['uptime_percent'] !== null ? rtrim(rtrim(number_format($stats['uptime_percent'], 3), '0'), '.').'%' : '—' }}
                            </div>
                        </div>

                        {{-- Filament dropdown: teleported to <body> and flips upward near the bottom,
                             so it is never hidden behind the next row or clipped by the card. --}}
                        <div class="sv-mon-menu">
                            <x-filament::dropdown placement="bottom-end" teleport width="xs">
                                <x-slot name="trigger">
                                    <button type="button" class="sv-mon-icon-btn" aria-label="Actions for {{ $monitor->name }}">⋯</button>
                                </x-slot>

                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item tag="a" :href="MonitorResource::getUrl('view', ['record' => $monitor])" icon="heroicon-o-eye">
                                        View details
                                    </x-filament::dropdown.list.item>
                                    @if ($monitor->status !== MonitorStatus::Paused)
                                        <x-filament::dropdown.list.item wire:click="checkMonitorNow({{ $monitor->id }})" icon="heroicon-o-arrow-path" x-on:click="close()">
                                            Check now
                                        </x-filament::dropdown.list.item>
                                        <x-filament::dropdown.list.item wire:click="pauseMonitor({{ $monitor->id }})" icon="heroicon-o-pause" x-on:click="close()">
                                            Pause
                                        </x-filament::dropdown.list.item>
                                    @else
                                        <x-filament::dropdown.list.item wire:click="resumeMonitor({{ $monitor->id }})" icon="heroicon-o-play" color="success" x-on:click="close()">
                                            Resume
                                        </x-filament::dropdown.list.item>
                                    @endif
                                    <x-filament::dropdown.list.item tag="a" :href="MonitorResource::getUrl('edit', ['record' => $monitor])" icon="heroicon-o-cog-6-tooth">
                                        Edit
                                    </x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>

                                <x-filament::dropdown.list>
                                    <x-filament::dropdown.list.item
                                        wire:click="deleteMonitor({{ $monitor->id }})"
                                        wire:confirm="Delete {{ $monitor->name }}, its extra checks and all check history?"
                                        icon="heroicon-o-trash"
                                        color="danger"
                                        x-on:click="close()"
                                    >
                                        Delete
                                    </x-filament::dropdown.list.item>
                                </x-filament::dropdown.list>
                            </x-filament::dropdown>
                        </div>
                    </div>
                @empty
                    <div class="sv-mon-empty">
                        @if ($search !== '' || $statusFilter !== 'all')
                            No monitors match your filters.
                        @else
                            <div style="font-size:16px;font-weight:600;color:var(--sv-text);margin-bottom:6px;">No monitors yet</div>
                            Create your first monitor with <strong>New</strong>, or use <strong>Import from websites</strong> to monitor every website in this workspace.
                        @endif
                    </div>
                @endforelse
            </div>
        </div>

        {{-- ===== Right rail ===== --}}
        <div class="sv-mon-rail" style="display:grid;gap:16px;">
            <div class="sv-mon-card" style="padding:18px;">
                <h3>Current status</h3>
                @php
                    $overallClass = $summary['down'] > 0 ? 'down' : ($summary['up'] > 0 ? 'up' : 'pending');
                @endphp
                <div class="sv-mon-dot sv-mon-big-dot {{ $overallClass }}">
                    {{ $overallClass === 'down' ? '▼' : ($overallClass === 'up' ? '▲' : '•') }}
                </div>
                <div class="sv-mon-counts">
                    <div><strong style="{{ $summary['down'] > 0 ? 'color:var(--sv-danger);' : '' }}">{{ $summary['down'] }}</strong><small>Down</small></div>
                    <div><strong>{{ $summary['up'] }}</strong><small>Up</small></div>
                    <div><strong>{{ $summary['paused'] }}</strong><small>Paused</small></div>
                </div>
                <p style="text-align:center;font-size:12px;color:var(--sv-muted);margin:12px 0 0;">
                    Using {{ $summary['total'] }} of {{ $monitorLimit }} {{ str('monitor')->plural($monitorLimit) }}
                </p>
                @if ($summary['total'] >= $monitorLimit)
                    <p style="text-align:center;margin:8px 0 0;">
                        <a href="{{ \App\Filament\App\Pages\ManageBillingSubscription::getUrl() }}" style="font-size:12px;font-weight:600;color:var(--sv-warning);text-decoration:none;">👑 Get more monitors</a>
                    </p>
                @endif
            </div>

            <div class="sv-mon-card" style="padding:18px;">
                <h3>Last 24 hours</h3>
                <div class="sv-mon-kpis">
                    <div>
                        <strong style="{{ $summary['uptime_percent'] !== null && $summary['uptime_percent'] < 99 ? 'color:var(--sv-danger);' : '' }}">
                            {{ $summary['uptime_percent'] !== null ? rtrim(rtrim(number_format($summary['uptime_percent'], 3), '0'), '.').'%' : '—' }}
                        </strong>
                        <small>Overall uptime</small>
                    </div>
                    <div>
                        <strong>{{ $summary['mtbf_seconds'] !== null ? round($summary['mtbf_seconds'] / 3600, 2).'h' : '—' }}</strong>
                        <small>MTBF</small>
                    </div>
                    <div>
                        <strong>{{ $summary['incidents'] }}</strong>
                        <small>Incidents</small>
                    </div>
                    <div>
                        <strong>{{ ListMonitors::formatSeconds($summary['downtime_seconds']) }}</strong>
                        <small>Downtime</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
