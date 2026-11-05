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
    <style>
        /* Light theme (default) */
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --surface-soft: #f1f5f9;
            --border: #e2e8f0;
            --primary: #059669;
            --primary-strong: #047857;
            --text: #0f172a;
            --text-soft: #334155;
            --muted: #64748b;
        }
        /* Dark theme */
        html.dark {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface-soft: #0f172a;
            --border: #334155;
            --primary: #10b981;
            --primary-strong: #34d399;
            --text: #f8fafc;
            --text-soft: #cbd5e1;
            --muted: #94a3b8;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.75;
            margin: 0;
            padding: 40px 24px;
            transition: background-color 0.2s, color 0.2s;
        }
        .container { max-width: 920px; margin: 0 auto; }
        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        a { color: var(--primary); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .legal-nav {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .legal-tab {
            padding: 8px 16px;
            border-radius: 8px;
            background: var(--surface);
            border: 1px solid var(--border);
            color: var(--text-soft);
            font-weight: 600;
            font-size: 14px;
        }
        .legal-tab.active {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--surface-soft);
        }
        .box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            margin-bottom: 24px;
        }
        .meta {
            color: var(--muted);
            font-size: 14px;
            margin-bottom: 20px;
        }
        .legal-body {
            color: var(--text-soft);
            white-space: pre-line;
        }
        .footer-note {
            text-align: center;
            color: var(--muted);
            font-size: 14px;
            margin-top: 32px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="topbar">
            <p style="margin:0;">
                <a href="{{ route('home') }}">&larr; Back to {{ $appName }} Home</a>
                &nbsp;|&nbsp;
                <a href="{{ route('docs') }}">Technical Docs</a>
                &nbsp;|&nbsp;
                <a href="/app">Customer Portal (/app)</a>
            </p>
            @include('partials.theme-toggle')
        </div>

        <nav class="legal-nav" aria-label="Legal Documents">
            <a href="{{ route('legal.terms') }}" class="legal-tab {{ $activeTab === 'terms' ? 'active' : '' }}">Terms of Service</a>
            <a href="{{ route('legal.privacy') }}" class="legal-tab {{ $activeTab === 'privacy' ? 'active' : '' }}">Privacy Policy</a>
            <a href="{{ route('legal.refund-policy') }}" class="legal-tab {{ $activeTab === 'refund' ? 'active' : '' }}">Refund Policy</a>
        </nav>

        <div class="box">
            <h1 style="margin-top:0; margin-bottom:8px;">{{ $pageTitle }}</h1>
            <div class="meta">
                Platform: <strong>{{ $appName }}</strong> &bull; Questions? Contact <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>
            </div>
            <div class="legal-body">{{ $content }}</div>
        </div>

        <div class="footer-note">
            &copy; {{ date('Y') }} {{ $appName }} &bull;
            <a href="{{ route('legal.terms') }}">Terms</a> &bull;
            <a href="{{ route('legal.privacy') }}">Privacy</a> &bull;
            <a href="{{ route('legal.refund-policy') }}">Refund Policy</a>
        </div>
    </div>
</body>
</html>

