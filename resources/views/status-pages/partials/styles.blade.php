<style>
    :root {
        --sp-bg: #f8fafc;
        --sp-surface: #ffffff;
        --sp-border: #e2e8f0;
        --sp-text: #0f172a;
        --sp-muted: #64748b;
        --sp-up: #059669;
        --sp-degraded: #d97706;
        --sp-down: #dc2626;
        --sp-maintenance: #2563eb;
        --sp-none: #cbd5e1;
    }

    @media (prefers-color-scheme: dark) {
        :root {
            --sp-bg: #0b1120;
            --sp-surface: #111827;
            --sp-border: #1f2937;
            --sp-text: #f1f5f9;
            --sp-muted: #94a3b8;
            --sp-up: #10b981;
            --sp-degraded: #f59e0b;
            --sp-down: #f43f5e;
            --sp-maintenance: #60a5fa;
            --sp-none: #334155;
        }
    }

    * { box-sizing: border-box; }
    body { margin: 0; background: var(--sp-bg); color: var(--sp-text); font: 15px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
    .sp-wrap { max-width: 860px; margin: 0 auto; padding: 40px 16px 56px; }
    .sp-card { background: var(--sp-surface); border: 1px solid var(--sp-border); border-radius: 14px; padding: 20px 22px; }
    .sp-muted { color: var(--sp-muted); }
    .sp-h1 { font-size: 26px; font-weight: 800; margin: 0; letter-spacing: -0.01em; }
    .sp-h2 { font-size: 17px; font-weight: 700; margin: 0 0 14px; }
    .sp-footer { margin-top: 32px; text-align: center; font-size: 13px; color: var(--sp-muted); }
    .sp-footer a { color: inherit; }
    .sp-sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0; }
</style>
