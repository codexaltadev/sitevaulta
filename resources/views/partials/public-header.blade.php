@php
    $appName = $appName ?? \App\Services\Settings\SystemSettingsService::get('general', 'app_name', 'SiteVaulta');
    $logoLightUrl = $logoLightUrl ?? \App\Services\Settings\SystemSettingsService::get('general', 'logo_light_url', '');
    $logoDarkUrl = $logoDarkUrl ?? \App\Services\Settings\SystemSettingsService::get('general', 'logo_dark_url', '');
    $isHome = request()->routeIs('home');
    $prefix = $isHome ? '' : '/';
@endphp
<header class="site-header">
    <div class="container nav">
        <a href="{{ url('/') }}" class="brand" aria-label="{{ $appName }} Home">
            @if(!empty($logoLightUrl) || !empty($logoDarkUrl))
                <img src="{{ $logoLightUrl ?: $logoDarkUrl }}" alt="{{ $appName }}" style="height:32px;width:auto;border-radius:6px;" />
            @else
                <svg viewBox="0 0 36 36" fill="none" aria-hidden="true">
                    <rect width="36" height="36" rx="10" fill="url(#brandGradHeader)" />
                    <path d="M18 8L27 12.5V20C27 25 23 29 18 31C13 29 9 25 9 20V12.5L18 8Z" stroke="#ffffff" stroke-width="2.2" stroke-linejoin="round" />
                    <path d="M14 19.5L16.8 22.3L22.5 16.5" stroke="#ffffff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                    <defs>
                        <linearGradient id="brandGradHeader" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#10b981" />
                            <stop offset="1" stop-color="#0891b2" />
                        </linearGradient>
                    </defs>
                </svg>
            @endif
            <span>{{ $appName }}</span>
        </a>

        <nav class="nav-links" aria-label="Main Navigation">
            <a href="{{ $prefix }}#live-intelligence">Live Radar</a>
            <a href="{{ $prefix }}#features">Features</a>
            <a href="{{ $prefix }}#pricing">Pricing</a>
            <a href="{{ route('docs') }}">Docs</a>

            <div class="nav-dropdown" data-nav-dropdown>
                <button type="button" class="nav-dropdown-trigger" aria-expanded="false" data-nav-dropdown-btn>
                    <span>Explore</span>
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 11.168l3.71-3.938a.75.75 0 111.08 1.04l-4.25 4.5a.75.75 0 01-1.08 0l-4.25-4.5a.75.75 0 01.02-1.06z" clip-rule="evenodd" />
                    </svg>
                </button>
                <div class="nav-dropdown-menu" role="menu">
                    <a href="{{ $prefix }}#how-it-works" role="menuitem">
                        <span class="nd-icon">⚡</span>
                        <span>
                            <strong>How It Works</strong>
                            <small>4-Step Automated Backup Pipeline</small>
                        </span>
                    </a>
                    <a href="{{ $prefix }}#forecast" role="menuitem">
                        <span class="nd-icon">🧮</span>
                        <span>
                            <strong>Storage Calculator</strong>
                            <small>Incremental Chain Savings Estimator</small>
                        </span>
                    </a>
                    <a href="{{ $prefix }}#live-intelligence" role="menuitem">
                        <span class="nd-icon">🛡️</span>
                        <span>
                            <strong>Read-Only Malware &amp; SSL Radar</strong>
                            <small>100% Non-Destructive Security &amp; Uptime</small>
                        </span>
                    </a>
                    <a href="{{ $prefix }}#faq" role="menuitem">
                        <span class="nd-icon">❓</span>
                        <span>
                            <strong>Frequently Asked Questions</strong>
                            <small>BYOS Storage, Encryption &amp; Alerts</small>
                        </span>
                    </a>
                    <div class="nd-divider"></div>
                    <div class="nd-legal-row">
                        <a href="{{ route('legal.terms') }}">Terms</a>
                        <span>&bull;</span>
                        <a href="{{ route('legal.privacy') }}">Privacy</a>
                        <span>&bull;</span>
                        <a href="{{ route('legal.refund-policy') }}">Refund Policy</a>
                    </div>
                </div>
            </div>

            <div class="nav-mobile-cta">
                <a href="/app/login" class="btn btn-ghost btn-sm">Sign In</a>
                <a href="/app/register" class="btn btn-primary btn-sm">Start Trial</a>
            </div>
        </nav>

        <div class="nav-actions">
            @include('partials.theme-toggle')
            <a href="/app/login" class="btn btn-ghost btn-sm hide-mobile">Sign In</a>
            <a href="/app/register" class="btn btn-primary btn-sm hide-mobile">
                <span>Start Free Trial</span>
                <span class="arrow" aria-hidden="true">&rarr;</span>
            </a>
            <button type="button" class="nav-burger" aria-label="Toggle navigation menu" aria-expanded="false">
                <span></span>
            </button>
        </div>
    </div>
</header>
