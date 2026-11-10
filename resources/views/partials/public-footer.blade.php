@php
    $appName = $appName ?? \App\Services\Settings\SystemSettingsService::get('general', 'app_name', 'SiteVaulta');
    $tagline = $tagline ?? \App\Services\Settings\SystemSettingsService::get('general', 'tagline', 'Your Sites. Your Storage. Your Control.');
    $supportEmail = $supportEmail ?? \App\Services\Settings\SystemSettingsService::get('general', 'support_email', 'support@sitevaulta.com');
    $supportPhone = $supportPhone ?? \App\Services\Settings\SystemSettingsService::get('general', 'support_phone', '');
    $footerText = $footerText ?? \App\Services\Settings\SystemSettingsService::get('general', 'footer_text', 'Enterprise-grade server and database backup automation with zero storage lock-in. Stream encrypted backups directly to your own cloud.');
    $isHome = request()->routeIs('home');
    $prefix = $isHome ? '' : '/';
@endphp
<footer class="site-footer">
    <div class="container footer-grid">
        <div class="footer-about">
            <a href="{{ url('/') }}" class="brand">
                <svg viewBox="0 0 36 36" fill="none" style="width:28px;height:28px;" aria-hidden="true">
                    <rect width="36" height="36" rx="8" fill="url(#brandGradFooter)" />
                    <path d="M18 8L27 12.5V20C27 25 23 29 18 31C13 29 9 25 9 20V12.5L18 8Z" stroke="#ffffff" stroke-width="2.2" stroke-linejoin="round" />
                    <defs>
                        <linearGradient id="brandGradFooter" x1="0" y1="0" x2="36" y2="36" gradientUnits="userSpaceOnUse">
                            <stop stop-color="#10b981" />
                            <stop offset="1" stop-color="#0891b2" />
                        </linearGradient>
                    </defs>
                </svg>
                <span>{{ $appName }}</span>
            </a>
            <p>{{ $footerText }}</p>
            @if(!empty($supportPhone))
                <p style="font-size:13px;margin-top:8px;">Phone: <a href="tel:{{ $supportPhone }}">{{ $supportPhone }}</a></p>
            @endif
        </div>

        <div>
            <h4>Product</h4>
            <ul>
                <li><a href="{{ $prefix }}#live-intelligence">Live Radar &amp; Security Scan</a></li>
                <li><a href="{{ $prefix }}#how-it-works">How It Works</a></li>
                <li><a href="{{ $prefix }}#features">Features</a></li>
                <li><a href="{{ $prefix }}#forecast">Storage Calculator</a></li>
                <li><a href="{{ $prefix }}#pricing">Plans &amp; Email Packages</a></li>
            </ul>
        </div>

        <div>
            <h4>Resources &amp; Legal</h4>
            <ul>
                <li><a href="{{ route('docs') }}">Technical Docs &amp; Restore Guide</a></li>
                <li><a href="{{ route('legal.terms') }}">Terms of Service</a></li>
                <li><a href="{{ route('legal.privacy') }}">Privacy Policy</a></li>
                <li><a href="{{ route('legal.refund-policy') }}">Refund Policy</a></li>
                <li><a href="mailto:{{ $supportEmail }}">Direct Support</a></li>
            </ul>
        </div>

        <div>
            <h4>Trust &amp; Security</h4>
            <ul>
                <li><a href="{{ route('docs') }}#verification">SSRF &amp; Domain Guard</a></li>
                <li><a href="{{ route('docs') }}#encryption">AES-256-GCM Architecture</a></li>
                <li><a href="{{ route('docs') }}#malware-scanner">100% Read-Only Malware Scan</a></li>
                <li><a href="{{ route('docs') }}#uptime-ssl">Live Uptime &amp; SSL Guard</a></li>
            </ul>
        </div>
    </div>

    <div class="container footer-bottom">
        <div>
            &copy; {{ date('Y') }} {{ $appName }} &bull; {{ $tagline }}
        </div>
        <div class="status-ok">
            <i></i>
            <span>All Backup Engines, Uptime Radar &amp; Security Scanners Operational</span>
        </div>
    </div>
</footer>
