{{--
    Theme-aware colour tokens for custom inline HTML rendered inside the Filament panels
    (setup guides, storage forecaster, billing tables, restore guides, plan previews, etc.).

    Injected into both panels via PanelsRenderHook::HEAD_END (see AppPanelProvider / AdminPanelProvider).
    Filament adds the `dark` class to <html> when dark mode is active.

    RULE: never hardcode hex colours in inline HTML inside app/Filament — use these variables, e.g.
        style='background:var(--sv-bg);border:1px solid var(--sv-border);color:var(--sv-text);'
--}}
<style>
    :root {
        --sv-bg: #f8fafc;
        --sv-surface: #f1f5f9;
        --sv-border: #e2e8f0;
        --sv-border-strong: #cbd5e1;
        --sv-text: #0f172a;
        --sv-text-soft: #1e293b;
        --sv-text-dim: #334155;
        --sv-muted: #64748b;
        --sv-primary: #059669;
        --sv-primary-strong: #047857;
        --sv-success: #047857;
        --sv-code: #065f46;
        --sv-info: #0369a1;
        --sv-warning: #b45309;
        --sv-highlight: #92400e;
        --sv-danger: #dc2626;
    }

    html.dark {
        --sv-bg: #0f172a;
        --sv-surface: #1e293b;
        --sv-border: #334155;
        --sv-border-strong: #475569;
        --sv-text: #f8fafc;
        --sv-text-soft: #e2e8f0;
        --sv-text-dim: #cbd5e1;
        --sv-muted: #94a3b8;
        --sv-primary: #10b981;
        --sv-primary-strong: #059669;
        --sv-success: #34d399;
        --sv-code: #a7f3d0;
        --sv-info: #38bdf8;
        --sv-warning: #fbbf24;
        --sv-highlight: #fde68a;
        --sv-danger: #f87171;
    }

    /* Denser layout for long editors (monitor create/edit), closer to UptimeRobot's compact form. */
    .sv-compact-form .fi-sc { gap: 0.875rem; }
    .sv-compact-form .fi-section-header { padding-block: 0.625rem; }
    .sv-compact-form .fi-section-content-ctn > .fi-section-content > * { padding: 1rem 1.25rem; }
    .sv-compact-form .fi-section-header-description { margin-top: 0.125rem; }
    .sv-compact-form .fi-fo-field-wrp-helper-text { margin-top: 0.25rem; }
    .sv-compact-form .noUi-pips { font-size: 11px; }
</style>

