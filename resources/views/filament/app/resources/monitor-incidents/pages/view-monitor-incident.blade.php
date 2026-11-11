@php
    use App\Filament\App\Resources\MonitorIncidents\Tables\MonitorIncidentsTable;
    use App\Filament\App\Resources\Monitors\Pages\ViewMonitor;

    $eventIcons = [
        'detected' => ['!', 'var(--sv-danger)'],
        'confirmed' => ['✓', 'var(--sv-danger)'],
        'alert_sent' => ['✉', 'var(--sv-info)'],
        'resolved' => ['▲', 'var(--sv-success)'],
    ];
@endphp

<x-filament-panels::page>
    <style>
        .sv-inc { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 20px; align-items: start; }
        @media (max-width: 1100px) { .sv-inc { grid-template-columns: minmax(0, 1fr); } }
        .sv-inc-card { background: var(--sv-surface); border: 1px solid var(--sv-border); border-radius: 12px; padding: 18px 20px; }
        .sv-inc-label { font-size: 13px; color: var(--sv-muted); margin: 0 0 6px; }
        .sv-inc-value { font-size: 20px; font-weight: 700; color: var(--sv-text); margin: 0; }
        .sv-inc-sub { font-size: 12px; color: var(--sv-muted); margin: 6px 0 0; }
        .sv-inc-title { font-size: 15px; font-weight: 700; color: var(--sv-text); margin: 0; }
        .sv-inc-title::after { content: '.'; color: var(--sv-primary); }
        .sv-inc-tabs { display: inline-flex; background: var(--sv-bg); border: 1px solid var(--sv-border); border-radius: 8px; padding: 2px; }
        .sv-inc-tabs button { font-size: 12px; padding: 4px 12px; border-radius: 6px; color: var(--sv-muted); }
        .sv-inc-tabs button.active { background: var(--sv-surface); color: var(--sv-primary); font-weight: 600; }
        .sv-inc-code { position: relative; margin-top: 12px; background: var(--sv-bg); border: 1px solid var(--sv-border); border-radius: 8px; padding: 12px 40px 12px 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; color: var(--sv-text); white-space: pre-wrap; overflow-wrap: anywhere; max-height: 280px; overflow-y: auto; }
        .sv-inc-copy { position: absolute; top: 8px; right: 8px; font-size: 11px; color: var(--sv-muted); border: 1px solid var(--sv-border); border-radius: 6px; padding: 2px 6px; background: var(--sv-surface); }
        .sv-inc-event { display: flex; gap: 12px; align-items: flex-start; padding: 10px 0; border-bottom: 1px solid var(--sv-border); }
        .sv-inc-event:last-child { border-bottom: 0; }
        .sv-inc-event-icon { width: 24px; height: 24px; border-radius: 999px; display: grid; place-items: center; font-size: 12px; font-weight: 700; flex: none; border: 2px solid currentColor; }
        .sv-inc-pill { font-size: 10px; font-weight: 700; text-transform: uppercase; border-radius: 4px; padding: 1px 6px; margin-left: 6px; }
    </style>

    <div wire:poll.60s class="sv-inc">
        <div style="display:grid;gap:16px;min-width:0;">
            <div class="sv-inc-card">
                <p class="sv-inc-label">Root cause</p>
                <p class="sv-inc-value">
                    {{ $incident->root_cause }}
                    <span style="font-weight:400;color:var(--sv-muted);">· {{ MonitorIncidentsTable::ROOT_CAUSE_TYPES[$incident->root_cause_type] ?? ucfirst($incident->root_cause_type) }}</span>
                </p>
                <p class="sv-inc-sub">
                    {{ $incident->monitor->targetLabel() }}
                    · {{ $incident->is_excluded_from_reports ? 'Excluded from reports' : 'Included in reports' }}
                </p>
                @if ($incident->root_cause_type === 'firewall')
                    <div style="margin-top:12px;padding:12px 14px;border-radius:10px;border:1px solid var(--sv-warning);background:color-mix(in srgb, var(--sv-warning) 10%, transparent);font-size:13px;color:var(--sv-text);">
                        <strong>The site is probably up for visitors — its firewall is blocking our monitor.</strong>
                        Allowlist our monitoring IP addresses and User-Agent in the site's firewall (Cloudflare, Wordfence, cPanel CSF…), then click "Check now" on the monitor.
                        <a href="{{ route('monitoring.ips') }}" target="_blank" rel="noopener" style="color:var(--sv-primary);font-weight:600;">See IPs &amp; step-by-step instructions ↗</a>
                    </div>
                @endif
            </div>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
                <div class="sv-inc-card">
                    <p class="sv-inc-label">Status</p>
                    <p class="sv-inc-value" style="color:{{ $isOngoing ? 'var(--sv-danger)' : 'var(--sv-success)' }};">{{ $incident->status->label() }}</p>
                    <p class="sv-inc-sub">Started at {{ $incident->started_at->setTimezone($timezone)->format('M j, Y, H:i:s T') }}</p>
                    @if ($incident->resolved_at)
                        <p class="sv-inc-sub">Resolved at {{ $incident->resolved_at->setTimezone($timezone)->format('M j, Y, H:i:s T') }}</p>
                    @endif
                </div>
                <div class="sv-inc-card">
                    <p class="sv-inc-label">Duration</p>
                    <p class="sv-inc-value">{{ ViewMonitor::formatDuration($durationSeconds) }}</p>
                    <p class="sv-inc-sub">{{ $isOngoing ? 'Still counting' : 'From detection to recovery' }}</p>
                </div>
                <div class="sv-inc-card">
                    <p class="sv-inc-label">Regions</p>
                    <p class="sv-inc-value" style="font-size:15px;">
                        <span style="color:{{ $isOngoing ? 'var(--sv-danger)' : 'var(--sv-success)' }};">●</span>
                        {{ \App\Services\Monitoring\MonitorCheckRunner::PROBE_LOCATION }}
                    </p>
                    <p class="sv-inc-sub">Region: {{ $incident->region }}</p>
                </div>
            </div>

            <div class="sv-inc-card">
                <h3 class="sv-inc-title">Activity log</h3>
                <div style="margin-top:8px;">
                    @forelse ($events as $event)
                        @php([$icon, $color] = $eventIcons[$event->type] ?? ['•', 'var(--sv-muted)'])
                        <div class="sv-inc-event">
                            <span class="sv-inc-event-icon" style="color:{{ $color }};">{{ $icon }}</span>
                            <div style="flex:1;min-width:0;font-size:13px;color:var(--sv-text);">
                                {{ $event->message }}
                                @if ($event->probe_location)
                                    <span style="color:var(--sv-muted);">by {{ $event->probe_location }}</span>
                                @endif
                                @if ($event->delivery_status)
                                    <span class="sv-inc-pill" style="{{ $event->delivery_status === 'success' ? 'background:color-mix(in srgb, var(--sv-success) 18%, transparent);color:var(--sv-success);' : 'background:color-mix(in srgb, var(--sv-danger) 18%, transparent);color:var(--sv-danger);' }}">{{ $event->delivery_status }}</span>
                                @endif
                            </div>
                            <span style="font-size:12px;color:var(--sv-muted);white-space:nowrap;">{{ $event->occurred_at->setTimezone($timezone)->format('M j, H:i:s') }}</span>
                        </div>
                    @empty
                        <p class="sv-inc-sub">No activity recorded.</p>
                    @endforelse
                </div>
            </div>
        </div>

        <div style="display:grid;gap:16px;min-width:0;">
            <div class="sv-inc-card" x-data="{ tab: 'url' }">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                    <h3 class="sv-inc-title">Request</h3>
                    <div class="sv-inc-tabs" role="tablist">
                        <button type="button" role="tab" :class="{ active: tab === 'url' }" @click="tab = 'url'">{{ $incident->monitor->type->usesUrl() ? 'URL' : 'Target' }}</button>
                        <button type="button" role="tab" :class="{ active: tab === 'headers' }" @click="tab = 'headers'">Headers</button>
                    </div>
                </div>
                <div class="sv-inc-code" x-show="tab === 'url'" x-ref="requestLine">{{ $requestLine ?: '—' }}<button type="button" class="sv-inc-copy" @click="navigator.clipboard.writeText($refs.requestLine.firstChild.textContent)">Copy</button></div>
                <div class="sv-inc-code" x-show="tab === 'headers'" x-cloak x-ref="requestHeaders">{{ $requestHeaders ?: 'No request headers recorded' }}<button type="button" class="sv-inc-copy" @click="navigator.clipboard.writeText($refs.requestHeaders.firstChild.textContent)">Copy</button></div>
            </div>

            <div class="sv-inc-card" x-data="{ tab: 'body' }">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                    <h3 class="sv-inc-title">Response</h3>
                    <div class="sv-inc-tabs" role="tablist">
                        <button type="button" role="tab" :class="{ active: tab === 'body' }" @click="tab = 'body'">Body</button>
                        <button type="button" role="tab" :class="{ active: tab === 'headers' }" @click="tab = 'headers'">Headers</button>
                    </div>
                </div>
                <div class="sv-inc-code" x-show="tab === 'body'" x-ref="responseBody">{{ $incident->response_body ?: 'No response body recorded (the server did not answer)' }}<button type="button" class="sv-inc-copy" @click="navigator.clipboard.writeText($refs.responseBody.firstChild.textContent)">Copy</button></div>
                <div class="sv-inc-code" x-show="tab === 'headers'" x-cloak x-ref="responseHeaders">{{ $responseHeaders ?: 'No response headers recorded' }}<button type="button" class="sv-inc-copy" @click="navigator.clipboard.writeText($refs.responseHeaders.firstChild.textContent)">Copy</button></div>
                <p class="sv-inc-sub">Body is truncated to the first 2 KB.</p>
            </div>
        </div>
    </div>
</x-filament-panels::page>
