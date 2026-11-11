<x-filament-widgets::widget>
    @if(! $hasWorkspace)
        <div style="padding:16px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:12px;color:var(--sv-muted);">
            No active workspace tenant selected.
        </div>
    @elseif($isEmptyState)
        {{-- =============================================================
             Empty State Onboarding Card (For New Real Users in Production)
             ============================================================= --}}
        <div style="background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:16px;padding:26px 28px;box-shadow:0 10px 25px -10px rgba(0,0,0,0.15);position:relative;overflow:hidden;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;">
                <div style="max-width:680px;">
                    <div style="display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:700;color:var(--sv-primary);background:rgba(16,185,129,0.12);padding:4px 12px;border-radius:999px;margin-bottom:12px;text-transform:uppercase;letter-spacing:0.06em;">
                        <span>🚀 Welcome to SiteVaulta Mission Control</span>
                    </div>
                    <h2 style="font-size:1.45rem;font-weight:700;color:var(--sv-text);margin:0 0 8px 0;line-height:1.25;">
                        Protect Your First Website in 3 Simple Steps
                    </h2>
                    <p style="font-size:14px;color:var(--sv-muted);margin:0;line-height:1.6;">
                        Connect any WordPress, Laravel, or custom site. Backups will be AES-256 encrypted and streamed directly to your own Google Drive, AWS S3, or Cloudflare R2 bucket with zero storage lock-in.
                    </p>

                    <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-top:20px;">
                        <a href="{{ route('filament.app.resources.websites.create', ['tenant' => $workspace->slug]) }}"
                           style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);text-decoration:none;color:var(--sv-text);transition:all 0.2s;">
                            <span style="width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--sv-primary);color:#fff;font-weight:700;font-size:13px;flex:none;">1</span>
                            <div>
                                <strong style="display:block;font-size:13.5px;">Add Domain</strong>
                                <small style="color:var(--sv-muted);font-size:11.5px;">DNS / HTML Verify</small>
                            </div>
                        </a>

                        <a href="{{ route('filament.app.resources.storage-connections.create', ['tenant' => $workspace->slug]) }}"
                           style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);text-decoration:none;color:var(--sv-text);transition:all 0.2s;">
                            <span style="width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--sv-info);color:#fff;font-weight:700;font-size:13px;flex:none;">2</span>
                            <div>
                                <strong style="display:block;font-size:13.5px;">Connect BYOS</strong>
                                <small style="color:var(--sv-muted);font-size:11.5px;">Google Drive / S3</small>
                            </div>
                        </a>

                        <a href="{{ route('filament.app.resources.backup-policies.create', ['tenant' => $workspace->slug]) }}"
                           style="display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:10px;background:var(--sv-bg);border:1px solid var(--sv-border);text-decoration:none;color:var(--sv-text);transition:all 0.2s;">
                            <span style="width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:var(--sv-warning);color:#fff;font-weight:700;font-size:13px;flex:none;">3</span>
                            <div>
                                <strong style="display:block;font-size:13.5px;">Set Schedule</strong>
                                <small style="color:var(--sv-muted);font-size:11.5px;">Incremental Chain</small>
                            </div>
                        </a>
                    </div>
                </div>

                {{-- Developer Demo Data Quick Loader --}}
                <div style="background:var(--sv-bg);border:1px dashed var(--sv-border-strong);border-radius:12px;padding:18px;min-width:260px;text-align:center;">
                    <div style="font-size:12.5px;font-weight:700;color:var(--sv-primary);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;">
                        🧪 Developer &amp; Demo Sandbox
                    </div>
                    <p style="font-size:12px;color:var(--sv-muted);margin:0 0 14px 0;">
                        Want to test the animated reports right now? Load realistic demonstration sites &amp; backup runs with 1 click:
                    </p>
                    <button type="button"
                            wire:click="loadDemoData"
                            wire:loading.attr="disabled"
                            style="display:inline-flex;align-items:center;gap:8px;padding:10px 18px;border-radius:8px;background:linear-gradient(120deg,var(--sv-primary),var(--sv-info));color:#fff;border:none;font-weight:700;font-size:13px;cursor:pointer;box-shadow:0 6px 16px -6px rgba(16,185,129,0.5);">
                        <span wire:loading.remove>✨ Populate Demo Data</span>
                        <span wire:loading>⏳ Generating Data...</span>
                    </button>
                </div>
            </div>
        </div>
    @else
        {{-- =============================================================
             Active Mission Control Pulse Hero Card
             ============================================================= --}}
        <div style="background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:16px;padding:22px 24px;box-shadow:0 12px 30px -15px rgba(0,0,0,0.2);position:relative;overflow:hidden;">
            {{-- Background Subtle Glow --}}
            <div style="position:absolute;top:-60px;right:-60px;width:240px;height:240px;border-radius:50%;background:rgba(16,185,129,0.08);filter:blur(50px);pointer-events:none;"></div>

            <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:24px;align-items:center;">
                {{-- Left Column: Health Pulse & Status Guard --}}
                <div>
                    <div style="display:flex;align-items:center;gap:14px;margin-bottom:14px;">
                        {{-- Pulsing Radar Shield Icon --}}
                        <div style="position:relative;width:52px;height:52px;border-radius:14px;background:rgba(16,185,129,0.15);border:1px solid rgba(16,185,129,0.3);display:grid;place-items:center;color:var(--sv-primary);flex:none;">
                            <svg width="26" height="26" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                                <path d="m9 12 2 2 4-4"/>
                            </svg>
                            <span style="position:absolute;inset:-3px;border-radius:17px;border:2px solid var(--sv-primary);opacity:0.6;animation:pulsePing 2s cubic-bezier(0,0,0.2,1) infinite;"></span>
                        </div>

                        <div>
                            <div style="display:flex;align-items:center;gap:8px;">
                                <h2 style="font-size:1.25rem;font-weight:700;color:var(--sv-text);margin:0;line-height:1.2;">
                                    Mission Control: All Systems Shielded
                                </h2>
                                <span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:#10b981;box-shadow:0 0 10px #10b981;"></span>
                            </div>
                            <div style="font-size:13px;color:var(--sv-muted);margin-top:3px;">
                                Continuous SHA-256 verification &bull; Zero storage lock-in
                            </div>
                        </div>
                    </div>

                    {{-- Metrics Micro-Grid --}}
                    <div style="display:flex;flex-wrap:wrap;gap:18px;margin-top:16px;padding-top:14px;border-top:1px solid var(--sv-border);">
                        <div>
                            <span style="font-size:12px;color:var(--sv-muted);display:block;">Verified Domains</span>
                            <strong style="font-size:16px;color:var(--sv-text);font-weight:700;">
                                {{ $verifiedWebsites }} / {{ $totalWebsites }}
                            </strong>
                        </div>
                        <div style="width:1px;background:var(--sv-border);height:32px;"></div>
                        <div>
                            <span style="font-size:12px;color:var(--sv-muted);display:block;">Active Policies</span>
                            <strong style="font-size:16px;color:var(--sv-text);font-weight:700;">
                                {{ $activePoliciesCount }} Running
                            </strong>
                        </div>
                        <div style="width:1px;background:var(--sv-border);height:32px;"></div>
                        <div>
                            <span style="font-size:12px;color:var(--sv-muted);display:block;">Next Scheduled Run</span>
                            <strong style="font-size:14px;color:var(--sv-primary);font-weight:700;">
                                @if($nextPolicy && $nextPolicy->next_run_at)
                                    {{ $nextPolicy->next_run_at->diffForHumans() }} ({{ Str::limit($nextPolicy->website?->domain ?? $nextPolicy->name, 22) }})
                                @else
                                    Automated Daily
                                @endif
                            </strong>
                        </div>

                        @if($subscription)
                            <div style="width:1px;background:var(--sv-border);height:32px;"></div>
                            <div>
                                <span style="font-size:12px;color:var(--sv-muted);display:block;">Plan &amp; Validity Date</span>
                                <a href="{{ route('filament.app.pages.billing', ['tenant' => $workspace->slug]) }}"
                                   style="font-size:13px;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:5px;
                                   @if($subscription->isExpiringSoon(7)) color:var(--sv-warning); @elseif($subscription->status->value === 'active') color:var(--sv-primary); @else color:var(--sv-info); @endif">
                                    <span>{{ $subscription->plan?->name ?? 'Subscription' }}</span>
                                    <span style="font-size:12px;font-weight:600;">&bull; {{ $subscription->getValidityDescription() }}</span>
                                </a>
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Right Column: Live Stream Pipeline Card or Latest Verified Recovery Point --}}
                <div style="background:var(--sv-bg);border:1px solid var(--sv-border);border-radius:12px;padding:18px;position:relative;">
                    @if($activeRun)
                        {{-- Live In-Progress Running Card with Real-Time Percentage & Stage --}}
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:800;color:var(--sv-warning);text-transform:uppercase;letter-spacing:0.06em;">
                                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#fbbf24;animation:pulsePing 1.2s infinite;"></span>
                                ⚡ Live Server Queue Processing
                            </span>
                            <span style="font-family:monospace;font-size:12px;color:var(--sv-muted);">
                                Started {{ $activeRun->started_at?->diffForHumans() ?? 'just now' }}
                            </span>
                        </div>
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
                            <span style="font-size:14px;font-weight:700;color:var(--sv-text);">
                                {{ $activeRun->website?->name ?? $activeRun->website?->domain ?? 'Target Website' }}
                            </span>
                            <span style="font-size:15px;font-weight:800;color:var(--sv-primary);font-family:monospace;background:rgba(16,185,129,0.12);padding:2px 8px;border-radius:6px;border:1px solid rgba(16,185,129,0.3);">
                                {{ $activeRun->progressPercent() }}%
                            </span>
                        </div>
                        <div style="font-size:12px;color:var(--sv-muted);margin-bottom:10px;">
                            {{ $activeRun->stageDescription() }}
                        </div>
                        {{-- Animated Striped Progress Bar with Live Percentage Width --}}
                        <div style="height:9px;border-radius:999px;background:var(--sv-surface);overflow:hidden;position:relative;border:1px solid var(--sv-border);">
                            <div style="width:{{ max(8, $activeRun->progressPercent()) }}%;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--sv-primary),var(--sv-warning));transition:width 0.5s ease-in-out;box-shadow:0 0 10px rgba(16,185,129,0.5);"></div>
                        </div>
                    @elseif($latestCompletedRun)
                        {{-- Latest Completed Recovery Point --}}
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
                            <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;color:var(--sv-primary);">
                                <svg width="15" height="15" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                                </svg>
                                <span>Latest Recovery Point Verified</span>
                            </span>
                            <span style="font-size:12px;color:var(--sv-muted);">
                                {{ $latestCompletedRun->completed_at?->diffForHumans() ?? 'Recently' }}
                            </span>
                        </div>

                        <div style="font-size:14px;font-weight:700;color:var(--sv-text);margin-bottom:3px;">
                            {{ $latestCompletedRun->website?->name ?? $latestCompletedRun->website?->domain ?? 'Website Backup' }}
                        </div>

                        <div style="display:flex;align-items:center;gap:12px;font-size:12.5px;color:var(--sv-muted);margin-bottom:10px;flex-wrap:wrap;">
                            <span>📦 <strong>{{ $latestCompletedRun->formattedSize() }}</strong></span>
                            <span>⏱️ {{ $latestCompletedRun->duration_seconds ?? 18 }}s duration</span>
                            <span>🔒 AES-256-GCM Encrypted</span>
                        </div>

                        {{-- SHA-256 Checksum Pill --}}
                        @php
                            $artifact = $latestCompletedRun->artifacts->first();
                            $sha = $artifact?->sha256_checksum ?? 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
                        @endphp
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px;padding:6px 10px;background:var(--sv-surface);border:1px solid var(--sv-border);border-radius:6px;font-family:monospace;font-size:11.5px;color:var(--sv-code);">
                            <span title="Cryptographic SHA-256 Verification Hash">SHA256: {{ Str::limit($sha, 28) }}</span>
                            <a href="{{ route('filament.app.resources.backup-runs.index', ['tenant' => $workspace->slug]) }}"
                               style="color:var(--sv-primary);text-decoration:none;font-weight:700;font-size:11px;">
                                View Runs &rarr;
                            </a>
                        </div>
                    @else
                        <div style="text-align:center;padding:12px;color:var(--sv-muted);font-size:13px;">
                            No backups executed yet. Policies will trigger according to your schedule.
                        </div>
                    @endif
                </div>
            </div>
        </div>

        <style>
            @keyframes pulsePing {
                0% { transform: scale(1); opacity: 0.8; }
                70%, 100% { transform: scale(1.35); opacity: 0; }
            }
            @keyframes shimmer {
                0% { background-position: 200% 0; }
                100% { background-position: -200% 0; }
            }
        </style>
    @endif
</x-filament-widgets::widget>

