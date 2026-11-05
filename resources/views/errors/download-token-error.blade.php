<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading ?? 'Download Link Notice' }} — SiteVaulta</title>
    @include('partials.theme-head')
    <style>
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --text: #0f172a;
            --text-soft: #475569;
            --text-dim: #64748b;
            --primary: #059669;
            --primary-hover: #047857;
            --warning: #d97706;
            --shadow: 0 20px 25px -5px rgba(15, 23, 42, 0.08), 0 8px 10px -6px rgba(15, 23, 42, 0.04);
        }
        html.dark {
            --bg: #0b1120;
            --surface: #1e293b;
            --border: #334155;
            --text: #f8fafc;
            --text-soft: #cbd5e1;
            --text-dim: #94a3b8;
            --primary: #10b981;
            --primary-hover: #059669;
            --warning: #f59e0b;
            --shadow: 0 25px 35px -5px rgba(0, 0, 0, 0.5), 0 10px 15px -5px rgba(0, 0, 0, 0.3);
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--bg);
            color: var(--text);
            margin: 0;
            padding: 24px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
        }
        .error-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 36px 32px;
            max-width: 520px;
            width: 100%;
            text-align: center;
            box-shadow: var(--shadow);
            animation: fadeIn 0.3s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .icon-circle {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: rgba(217, 119, 6, 0.12);
            color: var(--warning);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            margin-bottom: 20px;
        }
        h1 {
            font-size: 20px;
            font-weight: 800;
            color: var(--text);
            margin: 0 0 12px 0;
            line-height: 1.35;
        }
        .badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
            background: rgba(217, 119, 6, 0.15);
            color: var(--warning);
            margin-bottom: 16px;
            letter-spacing: 0.04em;
        }
        .reason-box {
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 14px 16px;
            font-size: 13.5px;
            color: var(--text-soft);
            line-height: 1.6;
            margin-bottom: 18px;
            text-align: left;
        }
        .reason-box strong {
            color: var(--text);
        }
        p.subtext {
            font-size: 13px;
            color: var(--text-dim);
            line-height: 1.6;
            margin: 0 0 24px 0;
        }
        .btn-action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            background: var(--primary);
            color: #ffffff;
            font-weight: 700;
            font-size: 14px;
            padding: 12px 24px;
            border-radius: 10px;
            text-decoration: none;
            transition: background 0.15s ease, transform 0.1s ease;
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.25);
        }
        .btn-action:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }
        .footer-note {
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-dim);
        }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="icon-circle">
            @if(($statusCode ?? 410) === 410)
                🔒
            @elseif(($statusCode ?? 410) === 404)
                🔍
            @else
                ⏰
            @endif
        </div>

        <div class="badge">HTTP {{ $statusCode ?? 410 }} &bull; PROTECTED SECURITY TOKEN</div>

        <h1>{{ $heading ?? 'Download Link No Longer Valid' }}</h1>

        <div class="reason-box">
            <strong>Security Notice:</strong><br>
            {{ $message ?? 'This one-time download link has already been used and is no longer valid.' }}
        </div>

        <p class="subtext">
            {{ $subtext ?? 'For maximum data security, SiteVaulta generates single-use expiring tokens. To download this backup archive again, return to your dashboard and generate a fresh link with one click.' }}
        </p>

        <a href="{{ url('/app') }}" class="btn-action">
            &larr; Return to Dashboard (Backup Runs)
        </a>

        <div class="footer-note">
            SiteVaulta Zero-Knowledge Cloud Backup Protection
        </div>
    </div>
</body>
</html>

