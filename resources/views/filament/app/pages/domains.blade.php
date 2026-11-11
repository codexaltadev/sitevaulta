@php
    use App\Enums\BackupRunStatus;
    use App\Enums\MonitorStatus;
    use App\Filament\App\Resources\Monitors\MonitorResource;
    use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;
    use App\Filament\App\Resources\VerifiedDomains\VerifiedDomainResource;
    use App\Filament\App\Resources\Websites\WebsiteResource;

    $verifiedDomainsUrl = VerifiedDomainResource::getUrl('index');
@endphp

<x-filament-panels::page>
    <style>
        .sv-dom-stats { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 12px; }
        @media (max-width: 900px) { .sv-dom-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .sv-dom-stat { background: var(--sv-surface); border: 1px solid var(--sv-border); border-radius: 12px; padding: 12px 16px; }
        .sv-dom-stat strong { display: block; font-size: 22px; color: var(--sv-text); }
        .sv-dom-stat span { font-size: 12px; color: var(--sv-muted); }
        .sv-dom-stat.is-alert strong { color: var(--sv-warning); }
        .sv-dom-stat.is-down strong { color: var(--sv-danger); }
        .sv-dom-toolbar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 4px 0 12px; }
        .sv-dom-input { flex: 1 1 220px; background: var(--sv-bg); border: 1px solid var(--sv-border); color: var(--sv-text); border-radius: 8px; padding: 7px 10px; font-size: 13px; }
        .sv-dom-tab { font-size: 12px; font-weight: 600; padding: 6px 12px; border-radius: 999px; border: 1px solid var(--sv-border); color: var(--sv-muted); background: var(--sv-surface); cursor: pointer; }
        .sv-dom-tab.is-active { color: var(--sv-bg); background: var(--sv-primary); border-color: var(--sv-primary); }
        .sv-dom-card { background: var(--sv-surface); border: 1px solid var(--sv-border); border-radius: 12px; overflow: hidden; }
        .sv-dom-head, .sv-dom-row { display: grid; grid-template-columns: minmax(180px, 1.3fr) repeat(3, minmax(0, 1fr)); gap: 16px; padding: 12px 16px; align-items: start; }
        .sv-dom-head { font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--sv-muted); border-bottom: 1px solid var(--sv-border); }
        .sv-dom-row { border-top: 1px solid var(--sv-border); }
        .sv-dom-head + .sv-dom-row { border-top: 0; }
        .sv-dom-row:hover { background: var(--sv-bg); }
        @media (max-width: 900px) { .sv-dom-head { display: none; } .sv-dom-row { grid-template-columns: minmax(0, 1fr); gap: 8px; } }
        .sv-dom-host { font-weight: 600; font-size: 14px; color: var(--sv-text); text-decoration: none; overflow-wrap: anywhere; }
        .sv-dom-host:hover { color: var(--sv-primary); }
        .sv-dom-issues { margin-top: 6px; display: grid; gap: 2px; }
        .sv-dom-issue { font-size: 12px; color: var(--sv-warning); }
        .sv-dom-label { display: none; font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--sv-muted); }
        @media (max-width: 900px) { .sv-dom-label { display: block; } }
        .sv-dom-badge { display: inline-block; font-size: 11px; font-weight: 700; border-radius: 4px; padding: 1px 6px; border: 1px solid var(--sv-border-strong); color: var(--sv-muted); }
        .sv-dom-badge.success { color: var(--sv-success); border-color: var(--sv-success); }
        .sv-dom-badge.warning { color: var(--sv-warning); border-color: var(--sv-warning); }
        .sv-dom-badge.danger { color: var(--sv-danger); border-color: var(--sv-danger); }
        .sv-dom-sub { font-size: 12px; color: var(--sv-muted); margin-top: 3px; }
        .sv-dom-link { font-size: 12px; font-weight: 600; color: var(--sv-primary); text-decoration: none; }
        .sv-dom-link:hover { text-decoration: underline; }
        .sv-dom-empty { padding: 40px 16px; text-align: center; color: var(--sv-muted); font-size: 13px; }
    </style>

    <div class="sv-dom-stats">
        <div class="sv-dom-stat"><strong>{{ $summary['domains'] }}</strong><span>Domains</span></div>
        <div class="sv-dom-stat"><strong>{{ $summary['verified'] }}</strong><span>Ownership verified</span></div>
        <div class="sv-dom-stat"><strong>{{ $summary['backed_up'] }}</strong><span>Backed up</span></div>
        <div @class(['sv-dom-stat', 'is-down' => $summary['down'] > 0])><strong>{{ $summary['down'] }}</strong><span>Down now</span></div>
        <div @class(['sv-dom-stat', 'is-alert' => $summary['attention'] > 0])><strong>{{ $summary['attention'] }}</strong><span>Need attention</span></div>
    </div>

    <div class="sv-dom-toolbar">
        <input type="search" class="sv-dom-input" placeholder="Search domain, website or monitor…" wire:model.live.debounce.300ms="search" aria-label="Search domains">
        <button type="button" @class(['sv-dom-tab', 'is-active' => $show === 'all']) wire:click="$set('show', 'all')">All</button>
        <button type="button" @class(['sv-dom-tab', 'is-active' => $show === 'attention']) wire:click="$set('show', 'attention')">Needs attention ({{ $summary['attention'] }})</button>
    </div>

    <div class="sv-dom-card">
        <div class="sv-dom-head">
            <span>Domain</span><span>Ownership</span><span>Backups</span><span>Uptime</span>
        </div>

        @forelse ($rows as $row)
            @php
                $ownership = $row['ownership'];
                $website = $row['website'];
                $monitor = $row['monitor'];
                $lastRun = $website?->latestBackupRun;
                $hostUrl = $monitor ? MonitorResource::getUrl('view', ['record' => $monitor]) : ($website ? WebsiteResource::getUrl('edit', ['record' => $website]) : $verifiedDomainsUrl);
            @endphp
            <div class="sv-dom-row" wire:key="domain-{{ $row['host'] }}">
                {{-- Domain + what needs attention --}}
                <div>
                    <a href="{{ $hostUrl }}" class="sv-dom-host">{{ $row['host'] }}</a>
                    @if ($row['issues'] !== [])
                        <div class="sv-dom-issues">
                            @foreach ($row['issues'] as $issue)
                                <span class="sv-dom-issue">⚠ {{ $issue }}</span>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Ownership (verified_domains) --}}
                <div>
                    <span class="sv-dom-label">Ownership</span>
                    @if ($ownership?->isInGracePeriod())
                        <span class="sv-dom-badge warning">Token missing</span>
                        <div class="sv-dom-sub">Put it back by {{ $ownership->graceEndsAt()?->setTimezone($timezone)->format('M j') }}</div>
                        <a href="{{ $verifiedDomainsUrl }}" class="sv-dom-link">How to verify →</a>
                    @elseif ($ownership?->isVerified())
                        <span class="sv-dom-badge success">✓ Verified</span>
                        <div class="sv-dom-sub">via {{ $ownership->methodLabel() }}</div>
                    @elseif ($ownership?->ownership_lapsed_at)
                        <span class="sv-dom-badge danger">Lapsed</span>
                        <div class="sv-dom-sub">since {{ $ownership->ownership_lapsed_at->setTimezone($timezone)->format('M j') }}</div>
                        <a href="{{ $verifiedDomainsUrl }}" class="sv-dom-link">Verify again →</a>
                    @elseif ($ownership)
                        <span class="sv-dom-badge warning">Pending</span>
                        <div><a href="{{ $verifiedDomainsUrl }}" class="sv-dom-link">Verify now →</a></div>
                    @else
                        <span class="sv-dom-badge">Not verified</span>
                        <div><a href="{{ $verifiedDomainsUrl }}" class="sv-dom-link">Verify →</a></div>
                    @endif
                </div>

                {{-- Backups (website) --}}
                <div>
                    <span class="sv-dom-label">Backups</span>
                    @if ($website)
                        <a href="{{ WebsiteResource::getUrl('edit', ['record' => $website]) }}" class="sv-dom-link">{{ $website->name }}</a>
                        <div class="sv-dom-sub">
                            @if ($lastRun)
                                <span @class(['sv-dom-badge', $lastRun->status->color() === 'gray' ? '' : $lastRun->status->color()])>{{ $lastRun->status === BackupRunStatus::Completed ? '✓ Last backup OK' : $lastRun->status->label() }}</span>
                                {{ $lastRun->created_at?->diffForHumans() }}
                            @else
                                No backup yet
                            @endif
                        </div>
                        <div class="sv-dom-sub">{{ $website->active_policies_count }} active {{ str('policy')->plural((int) $website->active_policies_count) }}</div>
                    @else
                        <span class="sv-dom-sub">Not backed up</span>
                        <div><a href="{{ WebsiteResource::getUrl('create') }}" class="sv-dom-link">Add backup →</a></div>
                    @endif
                </div>

                {{-- Uptime (monitor) --}}
                <div>
                    <span class="sv-dom-label">Uptime</span>
                    @if ($monitor)
                        <span @class(['sv-dom-badge', match ($monitor->status) { MonitorStatus::Up => 'success', MonitorStatus::Down => 'danger', MonitorStatus::Paused => 'warning', default => '' }])>{{ $monitor->status->label() }}</span>
                        <span class="sv-dom-sub">{{ ViewMonitor::formatPercent($row['uptime_percent']) }} · 24h</span>
                        @if ($monitor->children->isNotEmpty())
                            <div class="sv-dom-sub">
                                @foreach ($monitor->children as $child)
                                    <span @class(['sv-dom-badge', match ($child->status) { MonitorStatus::Up => 'success', MonitorStatus::Down => 'danger', default => '' }]) title="{{ $child->type->label() }}{{ $child->last_error ? ': '.$child->last_error : '' }}">{{ $child->type->shortLabel() }}</span>
                                @endforeach
                            </div>
                        @endif
                        <div><a href="{{ MonitorResource::getUrl('view', ['record' => $monitor]) }}" class="sv-dom-link">Open monitor →</a></div>
                    @else
                        <span class="sv-dom-sub">Not monitored</span>
                        <div><a href="{{ MonitorResource::getUrl('create') }}" class="sv-dom-link">Add monitor →</a></div>
                    @endif
                </div>
            </div>
        @empty
            <div class="sv-dom-empty">
                {{ $search !== '' || $show !== 'all' ? 'No domain matches.' : 'No domains yet — add a backup website, a monitor or a domain to verify.' }}
            </div>
        @endforelse
    </div>
</x-filament-panels::page>
