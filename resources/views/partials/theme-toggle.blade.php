{{--
    Dark / Light / System theme toggle button for public Blade pages.
    Requires @include('partials.theme-head') in the page <head>.

    Usage:
        @include('partials.theme-toggle')
        @include('partials.theme-toggle', ['fixed' => true])   (floating top-right)
--}}
<button
    type="button"
    class="theme-toggle{{ ! empty($fixed) ? ' theme-toggle-fixed' : '' }}"
    onclick="window.svCycleTheme()"
    title="Switch theme (Light → Dark → System)"
    aria-label="Switch colour theme (Light, Dark, System)"
>
    <span class="tt-item tt-light" aria-hidden="true">☀️ <span>Light</span></span>
    <span class="tt-item tt-dark" aria-hidden="true">🌙 <span>Dark</span></span>
    <span class="tt-item tt-system" aria-hidden="true">🖥️ <span>System</span></span>
</button>
