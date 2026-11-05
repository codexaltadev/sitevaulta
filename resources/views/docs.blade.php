<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documentation & Restore Guide — {{ $appName }}</title>
    @include('partials.theme-head')
    <style>
        /* Light theme (default) */
        :root {
            --bg: #f8fafc;
            --surface: #ffffff;
            --border: #e2e8f0;
            --primary: #059669;
            --text: #0f172a;
            --code-text: #065f46;
            --code-bg: #f1f5f9;
        }
        /* Dark theme */
        html.dark {
            --bg: #0f172a;
            --surface: #1e293b;
            --border: #334155;
            --primary: #10b981;
            --text: #f8fafc;
            --code-text: #a7f3d0;
            --code-bg: #1e293b;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.7;
            margin: 0;
            padding: 40px 24px;
            transition: background-color 0.2s, color 0.2s;
        }
        .container { max-width: 920px; margin: 0 auto; }
        .topbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        a { color: var(--primary); }
        pre {
            background: var(--code-bg);
            border: 1px solid var(--border);
            padding: 16px;
            border-radius: 8px;
            overflow-x: auto;
            color: var(--code-text);
        }
        .box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 24px;
            margin-bottom: 24px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="topbar">
            <p><a href="/">&larr; Back to {{ $appName }} Home</a> | <a href="/app">Go to Customer Portal (/app)</a></p>
            @include('partials.theme-toggle')
        </div>
        <h1>{{ $appName }} — Technical Documentation & Restore Guide</h1>

        <div class="box">
            <h2>1. Domain Ownership Verification</h2>
            <p>Before any server connection or scheduled backup can execute, verify domain ownership using one of three methods:</p>
            <ul>
                <li><strong>DNS TXT Record:</strong> Add <code>_sitevaulta-verify.yourdomain.com</code> with your unique token.</li>
                <li><strong>HTML Verification File:</strong> Upload <code>/.well-known/sitevaulta-verify.txt</code> to your web root.</li>
                <li><strong>Homepage Meta Tag:</strong> Add <code>&lt;meta name="sitevaulta-verification" content="sv-verify-..."&gt;</code>.</li>
            </ul>
        </div>

        <div class="box">
            <h2>2. Pre-Deployment CI/CD Backup Webhook API</h2>
            <p>Trigger an automated pre-deploy backup before pushing code from GitHub Actions or GitLab CI:</p>
            <pre>curl -X POST "{{ url('/api/v1/websites/{website_uuid}/trigger-backup') }}" \
  -H "X-Webhook-Token: YOUR_WEBSITE_WEBHOOK_SECRET" \
  -H "Content-Type: application/json" \
  -d '{"sync": false}'</pre>
        </div>

        <div class="box">
            <h2>3. Manual SHA-256 Verification & Decryption Guide</h2>
            <p>Every backup run produces a SHA-256 checksum and a context-aware restore guide inside <strong>Backup Runs & Restore</strong>:</p>
            <pre># 1. Verify archive integrity
sha256sum -c backup-archive.zip.sha256

# 2. Extract files and restore database
unzip backup-archive.zip -d ./restore-staging
gunzip < ./restore-staging/database/db-dump.sql.gz | mysql -u db_user -p db_name</pre>
        </div>
    </div>
</body>
</html>

