<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $pageTitle }} — {{ $appName }}</title>
    @if(!empty($faviconUrl))
        <link rel="icon" href="{{ $faviconUrl }}">
    @endif

    @include('partials.theme-head')

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />
    <link rel="stylesheet" href="{{ asset('assets/landing/landing.css') }}">
</head>
<body>

    @include('partials.public-header')

    <main class="section" style="padding-top: 56px; padding-bottom: 40px;">
        <div class="container" style="max-width: 960px;">
            <div class="section-head" style="text-align: left; margin-bottom: 28px;" data-reveal>
                <span class="kicker">Legal &amp; Compliance</span>
                <h1 style="font-size: clamp(2rem, 3.5vw, 2.75rem); margin: 10px 0 12px;">{{ $pageTitle }}</h1>
                <p style="margin: 0; color: var(--muted);">
                    Platform: <strong>{{ $appName }}</strong> &bull; Need assistance? Contact <a href="mailto:{{ $supportEmail }}" style="color: var(--primary); font-weight: 600;">{{ $supportEmail }}</a>
                </p>
            </div>

            <nav aria-label="Legal Documents" style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 28px;" data-reveal>
                <a href="{{ route('legal.terms') }}"
                   class="btn {{ $activeTab === 'terms' ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                    Terms of Service
                </a>
                <a href="{{ route('legal.privacy') }}"
                   class="btn {{ $activeTab === 'privacy' ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                    Privacy Policy
                </a>
                <a href="{{ route('legal.refund-policy') }}"
                   class="btn {{ $activeTab === 'refund' ? 'btn-primary' : 'btn-ghost' }} btn-sm">
                    Refund &amp; Cancellation Policy
                </a>
                <a href="{{ route('docs') }}" class="btn btn-ghost btn-sm">
                    Technical Docs &amp; Restore Guide
                </a>
            </nav>

            <div class="panel" style="padding: 36px; border-radius: var(--radius-lg); background: var(--surface); border: 1px solid var(--border); box-shadow: var(--shadow);" data-reveal>
                <div style="white-space: pre-line; line-height: 1.85; font-size: 15.5px; color: var(--text);">
                    {{ $content }}
                </div>
            </div>
        </div>
    </main>

    @include('partials.public-footer')

    <script src="{{ asset('assets/landing/landing.js') }}"></script>
</body>
</html>
