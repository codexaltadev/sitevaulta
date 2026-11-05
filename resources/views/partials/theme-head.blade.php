{{--
    Dark & Light mode bootstrap for public (non-Filament) Blade pages.

    Include this INSIDE <head>, BEFORE the page <style> block, so the correct theme is
    applied before first paint (no flash of the wrong theme):

        @include('partials.theme-head')

    - Stores the preference in localStorage key `filament:theme:mode` — the same key the
      Filament panels (/app, /admin) use, so the choice is shared across the whole product.
    - Supported modes: `light`, `dark`, `system` (default; follows the OS setting).
    - Adds the `dark` class to <html> when dark mode is active, and sets
      `data-theme-mode` on <html> to the selected mode.
    - Pages define light colours on `:root` and dark overrides on `html.dark`.
--}}
<meta name="color-scheme" content="light dark">
<script>
    (function () {
        var KEY = 'filament:theme:mode';
        var MODES = ['light', 'dark', 'system'];
        var media = window.matchMedia('(prefers-color-scheme: dark)');

        function getMode() {
            var mode = null;
            try { mode = localStorage.getItem(KEY); } catch (e) {}
            return MODES.indexOf(mode) === -1 ? 'system' : mode;
        }

        function apply() {
            var mode = getMode();
            var isDark = mode === 'dark' || (mode === 'system' && media.matches);
            var root = document.documentElement;
            root.classList.toggle('dark', isDark);
            root.setAttribute('data-theme-mode', mode);
        }

        window.svSetTheme = function (mode) {
            try { localStorage.setItem(KEY, mode); } catch (e) {}
            apply();
        };

        window.svCycleTheme = function () {
            var next = MODES[(MODES.indexOf(getMode()) + 1) % MODES.length];
            window.svSetTheme(next);
        };

        apply();

        if (media.addEventListener) {
            media.addEventListener('change', apply);
        } else if (media.addListener) {
            media.addListener(apply);
        }

        // Keep multiple open tabs in sync.
        window.addEventListener('storage', function (event) {
            if (event.key === KEY) apply();
        });
    })();
</script>
<style>
    .theme-toggle {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 8px 12px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--surface);
        color: var(--text);
        font: inherit;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        line-height: 1;
        transition: background-color 0.2s, border-color 0.2s, color 0.2s;
    }
    .theme-toggle:hover { border-color: var(--primary); }
    .theme-toggle:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
    .theme-toggle .tt-item { display: none; align-items: center; gap: 6px; }
    html[data-theme-mode="light"] .theme-toggle .tt-light,
    html[data-theme-mode="dark"] .theme-toggle .tt-dark,
    html[data-theme-mode="system"] .theme-toggle .tt-system,
    html:not([data-theme-mode]) .theme-toggle .tt-system { display: inline-flex; }
    .theme-toggle-fixed { position: fixed; top: 16px; right: 16px; z-index: 50; }
</style>

