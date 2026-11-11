<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $statusPage->name }} · Status</title>
    @include('status-pages.partials.styles')
    <style>
        .sp-input { width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--sp-border); background: var(--sp-bg); color: var(--sp-text); font-size: 15px; }
        .sp-input:focus { outline: 2px solid var(--sp-up); outline-offset: -1px; }
        .sp-button { width: 100%; margin-top: 12px; padding: 10px 12px; border: 0; border-radius: 10px; background: var(--sp-up); color: #fff; font-weight: 700; font-size: 15px; cursor: pointer; }
    </style>
</head>
<body>
    <main class="sp-wrap" style="max-width:420px;padding-top:80px;">
        <div class="sp-card">
            <h1 class="sp-h1" style="font-size:22px;">🔒 {{ $statusPage->name }}</h1>
            <p class="sp-muted" style="margin:8px 0 18px;">This status page is private. Enter the password to view it.</p>
            <form method="POST" action="{{ route('status-pages.unlock', ['slug' => $statusPage->slug]) }}">
                @csrf
                <label for="password" class="sp-muted" style="display:block;font-size:13px;margin-bottom:6px;">Password</label>
                <input id="password" name="password" type="password" class="sp-input" required autofocus autocomplete="current-password">
                @error('password')
                    <p style="color:var(--sp-down);font-size:13px;margin:8px 0 0;">{{ $message }}</p>
                @enderror
                <button type="submit" class="sp-button">View status</button>
            </form>
        </div>
        <footer class="sp-footer">Powered by {{ config('app.name', 'SiteVaulta') }}</footer>
    </main>
</body>
</html>
