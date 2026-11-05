<x-filament-widgets::widget>
    @if(! $hasWorkspace)
        {{-- No workspace --}}
    @else
        <div style="background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:16px;padding:22px 24px;box-shadow:0 12px 30px -15px rgba(0,0,0,0.2);">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
                <div style="display:flex;align-items:center;gap:10px;">
                    <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:var(--sv-primary);box-shadow:0 0 10px var(--sv-primary);"></span>
                    <h3 style="font-size:16px;font-weight:700;color:var(--sv-text);margin:0;">
                        Recent Backup Activity Stream &amp; Recovery Points
                    </h3>
                </div>
                <a href="{{ route('filament.app.resources.backup-runs.index', ['tenant' => $workspace->slug]) }}"
                   style="font-size:13px;color:var(--sv-primary);font-weight:700;text-decoration:none;">
                    View All Runs &rarr;
                </a>
            </div>

            @if($runs->isEmpty())
                <div style="text-align:center;padding:32px 20px;border:1px dashed var(--sv-border);border-radius:12px;color:var(--sv-muted);">
                    <div style="font-size:32px;margin-bottom:8px;">📦</div>
                    <strong style="display:block;font-size:14px;color:var(--sv-text);margin-bottom:4px;">No Backup Runs Yet</strong>
                    <p style="font-size:13px;margin:0 0 16px 0;">Backup runs will stream automatically according to your policies.</p>
                    <a href="{{ route('filament.app.resources.backup-policies.create', ['tenant' => $workspace->slug]) }}"
                       style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;background:var(--sv-primary);color:#fff;text-decoration:none;font-size:13px;font-weight:700;">
                        + Create Backup Policy
                    </a>
                </div>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:13px;text-align:left;">
                        <thead>
                            <tr style="border-bottom:1px solid var(--sv-border);color:var(--sv-muted);font-size:11.5px;text-transform:uppercase;letter-spacing:0.06em;">
                                <th style="padding:10px 12px 10px 0;">Target Website</th>
                                <th style="padding:10px 12px;">Type &amp; Level</th>
                                <th style="padding:10px 12px;">Destination Vault</th>
                                <th style="padding:10px 12px;">Archive Size</th>
                                <th style="padding:10px 12px;">Duration</th>
                                <th style="padding:10px 12px;">SHA-256 Checksum</th>
                                <th style="padding:10px 0 10px 12px;text-align:right;">Restore &amp; Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($runs as $run)
                                @php
                                    $artifact = $run->artifacts->first();
                                    $storageConn = $artifact?->storageConnection;
                                    $sha = $artifact?->sha256_checksum ?? '—';
                                    $statusColor = match($run->status->value) {
                                        'completed' => '#10b981',
                                        'queued', 'connecting', 'extracting', 'packaging', 'uploading', 'verifying' => '#fbbf24',
                                        default => '#f87171',
                                    };
                                @endphp
                                <tr style="border-bottom:1px solid var(--sv-border);">
                                    {{-- Target Website --}}
                                    <td style="padding:12px 12px 12px 0;">
                                        <div style="display:flex;align-items:center;gap:8px;">
                                            <span style="width:8px;height:8px;border-radius:50%;background:{{ $statusColor }};box-shadow:0 0 6px {{ $statusColor }};flex:none;"></span>
                                            <div>
                                                <strong style="color:var(--sv-text);display:block;font-size:13.5px;">
                                                    {{ $run->website?->name ?? 'Unknown Domain' }}
                                                </strong>
                                                <small style="color:var(--sv-muted);font-family:monospace;font-size:11.5px;">
                                                    {{ $run->website?->domain }}
                                                </small>
                                            </div>
                                        </div>
                                    </td>

                                    {{-- Scope & Level --}}
                                    <td style="padding:12px;">
                                        <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
                                            @if($run->trigger_type === 'manual')
                                                <span style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:700;background:rgba(245,158,11,0.15);color:#d97706;border:1px solid rgba(245,158,11,0.3);">
                                                    ⚡ Manual
                                                </span>
                                            @else
                                                <span style="display:inline-flex;align-items:center;gap:3px;padding:2px 7px;border-radius:6px;font-size:11px;font-weight:700;background:rgba(16,185,129,0.15);color:#059669;border:1px solid rgba(16,185,129,0.3);">
                                                    ⏰ Auto (Cron)
                                                </span>
                                            @endif
                                            <span style="display:inline-block;padding:2px 8px;border-radius:6px;font-size:11.5px;font-weight:700;
                                                @if($run->backup_type === 'full')
                                                    background:rgba(59,130,246,0.12);color:var(--sv-info);
                                                @else
                                                    background:rgba(16,185,129,0.12);color:var(--sv-primary);
                                                @endif">
                                                {{ ucfirst($run->backup_type ?? 'Run') }} &bull; {{ $run->scope->label() }}
                                            </span>
                                        </div>
                                    </td>

                                    {{-- Storage Destination --}}
                                    <td style="padding:12px;color:var(--sv-text-soft);">
                                        <div style="display:flex;align-items:center;gap:6px;">
                                            <span>
                                                @if($storageConn?->driver?->value === 'google_drive')
                                                    📁 Google Drive
                                                @elseif($storageConn?->driver?->value === 's3')
                                                    🪣 AWS S3
                                                @elseif($storageConn?->driver?->value === 'cloudflare_r2')
                                                    ⚡ Cloudflare R2
                                                @else
                                                    🖥️ {{ $storageConn?->name ?? 'Cloud Storage' }}
                                                @endif
                                            </span>
                                        </div>
                                    </td>

                                    {{-- Archive Size or In-Progress Percentage --}}
                                    <td style="padding:12px;font-family:monospace;font-weight:700;color:var(--sv-text);">
                                        @if($run->status->isInProgress())
                                            <span style="display:inline-flex;align-items:center;gap:4px;color:var(--sv-warning);font-size:12px;">
                                                <span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#fbbf24;animation:pulsePing 1.2s infinite;"></span>
                                                {{ $run->progressPercent() }}%
                                            </span>
                                            <small style="display:block;font-size:10.5px;color:var(--sv-muted);font-weight:normal;">
                                                {{ $run->status->label() }}
                                            </small>
                                        @else
                                            {{ $run->formattedSize() }}
                                            @if($run->raw_source_size_bytes && $run->raw_source_size_bytes > $run->archive_size_bytes)
                                                <span style="font-size:10.5px;color:var(--sv-primary);font-weight:600;display:block;">
                                                    Compressed
                                                </span>
                                            @endif
                                        @endif
                                    </td>

                                    {{-- Duration --}}
                                    <td style="padding:12px;color:var(--sv-muted);font-family:monospace;">
                                        @if($run->duration_seconds)
                                            {{ $run->duration_seconds }}s
                                        @else
                                            —
                                        @endif
                                    </td>

                                    {{-- SHA-256 Checksum Pill --}}
                                    <td style="padding:12px;">
                                        <span style="display:inline-block;padding:3px 7px;border-radius:4px;background:var(--sv-bg);border:1px solid var(--sv-border);font-family:monospace;font-size:11px;color:var(--sv-code);"
                                              title="SHA-256: {{ $sha }}">
                                            {{ Str::limit($sha, 18) }}
                                        </span>
                                    </td>

                                    {{-- Restore Action --}}
                                    <td style="padding:12px 0 12px 12px;text-align:right;">
                                        <a href="{{ route('filament.app.resources.backup-runs.index', ['tenant' => $workspace->slug]) }}"
                                           style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;color:var(--sv-primary);text-decoration:none;background:var(--sv-bg);border:1px solid var(--sv-border);padding:4px 10px;border-radius:6px;">
                                            <span>Restore Guide</span>
                                            <span aria-hidden="true">&rarr;</span>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</x-filament-widgets::widget>

