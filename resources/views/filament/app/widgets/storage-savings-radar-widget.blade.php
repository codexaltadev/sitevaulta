<x-filament-widgets::widget>
    @if(! $hasWorkspace)
        {{-- No workspace --}}
    @else
        <div style="background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:16px;padding:22px 24px;box-shadow:0 12px 30px -15px rgba(0,0,0,0.2);">
            <div style="display:grid;grid-template-columns:1.15fr 1fr;gap:26px;align-items:stretch;">
                {{-- Left: Incremental Compression & Savings Radar --}}
                <div style="display:flex;flex-direction:column;justify-content:space-between;padding-right:8px;">
                    <div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                            <div style="display:flex;align-items:center;gap:8px;">
                                <span style="width:10px;height:10px;border-radius:50%;background:var(--sv-primary);box-shadow:0 0 10px var(--sv-primary);"></span>
                                <h3 style="font-size:15.5px;font-weight:700;color:var(--sv-text);margin:0;">
                                    Storage Footprint &amp; Delta Savings
                                </h3>
                            </div>
                            <span style="font-size:11.5px;color:var(--sv-muted);background:var(--sv-bg);border:1px solid var(--sv-border);padding:3px 8px;border-radius:6px;font-weight:600;">
                                Zero Storage Lock-In
                            </span>
                        </div>

                        <p style="font-size:13px;color:var(--sv-muted);margin:0 0 16px 0;line-height:1.5;">
                            SiteVaulta packages AES-256-GCM encrypted deltas while guaranteeing base recovery points are chain-safe and never orphaned.
                        </p>

                        {{-- Comparative Bar 1: Naive Full Backups --}}
                        <div style="margin-bottom:14px;">
                            <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px;color:var(--sv-muted);">
                                <span>Traditional Naive Daily Full Backups</span>
                                <strong style="font-family:monospace;color:var(--sv-text-soft);">{{ $naiveRawFormatted }}</strong>
                            </div>
                            <div style="height:10px;border-radius:999px;background:var(--sv-bg);border:1px solid var(--sv-border);overflow:hidden;">
                                <div style="width:100%;height:100%;border-radius:inherit;background:linear-gradient(90deg,#f59e0b,#ef4444);opacity:0.85;"></div>
                            </div>
                        </div>

                        {{-- Comparative Bar 2: Smart SiteVaulta Stored Archive --}}
                        <div style="margin-bottom:18px;">
                            <div style="display:flex;justify-content:space-between;font-size:12.5px;margin-bottom:6px;color:var(--sv-text);">
                                <span style="font-weight:700;color:var(--sv-primary);">SiteVaulta Smart Compressed Delta Chains</span>
                                <strong style="font-family:monospace;color:var(--sv-primary);font-size:13.5px;">{{ $actualStoredFormatted }}</strong>
                            </div>
                            <div style="height:12px;border-radius:999px;background:var(--sv-bg);border:1px solid var(--sv-border);overflow:hidden;position:relative;">
                                <div style="width:{{ $barRatio }}%;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--sv-primary),var(--sv-info));box-shadow:0 0 12px rgba(16,185,129,0.5);transition:width 1s ease;"></div>
                            </div>
                        </div>
                    </div>

                    {{-- Savings Pill --}}
                    <div>
                        <div style="display:inline-flex;align-items:center;gap:8px;padding:8px 14px;border-radius:10px;background:rgba(16,185,129,0.12);border:1px solid rgba(16,185,129,0.3);color:var(--sv-primary);font-size:13px;font-weight:700;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
                            <span>Saved {{ $savedFormatted }} ({{ $savedPercent }}%) Cloud Storage &amp; Bandwidth</span>
                        </div>
                    </div>
                </div>

                {{-- Right: Connected Cloud Storage (BYOS) Grid --}}
                <div style="border-left:1px solid var(--sv-border);padding-left:24px;display:flex;flex-direction:column;justify-content:space-between;">
                    <div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;">
                            <h3 style="font-size:15px;font-weight:700;color:var(--sv-text);margin:0;">
                                Cloud Storage Destinations (BYOS)
                            </h3>
                            <a href="{{ route('filament.app.resources.storage-connections.index', ['tenant' => $workspace->slug]) }}"
                               style="font-size:12px;color:var(--sv-primary);text-decoration:none;font-weight:700;">
                                Manage &rarr;
                            </a>
                        </div>

                        <div style="display:grid;gap:10px;">
                            @forelse($storageConnections as $storage)
                                <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);">
                                    <div style="display:flex;align-items:center;gap:10px;">
                                        {{-- Storage Driver Icon Badge --}}
                                        <div style="width:32px;height:32px;border-radius:8px;display:grid;place-items:center;background:var(--sv-surface);border:1px solid var(--sv-border);font-size:14px;">
                                            @if($storage->driver->value === 'google_drive')
                                                📁
                                            @elseif($storage->driver->value === 's3')
                                                🪣
                                            @elseif($storage->driver->value === 'cloudflare_r2')
                                                ⚡
                                            @else
                                                🖥️
                                            @endif
                                        </div>
                                        <div>
                                            <strong style="display:block;font-size:13px;color:var(--sv-text);">{{ $storage->name }}</strong>
                                            <small style="color:var(--sv-muted);font-size:11px;">
                                                {{ $storage->driver->label() }} &bull; Verified {{ $storage->last_verified_at?->diffForHumans() ?? 'recently' }}
                                            </small>
                                        </div>
                                    </div>

                                    <div style="text-align:right;">
                                        <span style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;font-weight:700;color:var(--sv-primary);background:rgba(16,185,129,0.1);padding:3px 8px;border-radius:999px;">
                                            <span style="width:6px;height:6px;border-radius:50%;background:#10b981;"></span>
                                            Healthy
                                        </span>
                                    </div>
                                </div>
                            @empty
                                <div style="text-align:center;padding:18px;border:1px dashed var(--sv-border);border-radius:10px;color:var(--sv-muted);font-size:13px;">
                                    No cloud storage destination connected yet.
                                </div>
                            @endforelse
                        </div>
                    </div>

                    <div style="margin-top:14px;padding-top:10px;border-top:1px solid var(--sv-border);display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-size:12px;color:var(--sv-muted);">Want to add Google Drive or Amazon S3?</span>
                        <a href="{{ route('filament.app.resources.storage-connections.create', ['tenant' => $workspace->slug]) }}"
                           style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--sv-primary);text-decoration:none;">
                            + Connect Destination
                        </a>
                    </div>
                </div>
            </div>
        </div>
    @endif
</x-filament-widgets::widget>

