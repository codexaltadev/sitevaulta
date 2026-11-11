<?php

namespace App\Providers\Filament;

use App\Filament\App\Pages\Tenancy\EditWorkspaceSettings;
use App\Filament\App\Pages\Tenancy\RegisterWorkspace;
use App\Filament\App\Widgets\RecentBackupStreamWidget;
use App\Filament\App\Widgets\StorageSavingsRadarWidget;
use App\Filament\App\Widgets\WorkspaceHealthPulseWidget;
use App\Filament\App\Widgets\WorkspaceOverviewWidget;
use App\Models\Workspace;
use App\Services\Settings\SystemSettingsService;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->registration()
            ->profile()
            ->brandName(fn () => (string) SystemSettingsService::get('general', 'app_name', 'SiteVaulta'))
            ->brandLogo(fn () => SystemSettingsService::get('general', 'logo_light_url') ?: null)
            ->darkModeBrandLogo(fn () => SystemSettingsService::get('general', 'logo_dark_url') ?: null)
            ->favicon(fn () => SystemSettingsService::get('general', 'favicon_url') ?: null)
            // Dark & Light mode: users can switch Light / Dark / System from the user menu.
            // Preference is stored in localStorage key `filament:theme:mode` (shared with public pages).
            // Use the full width next to the sidebar instead of Filament's centred 7xl (1280px) column.
            ->maxContentWidth(Width::Full)
            ->darkMode(true)
            ->themeSwitcher(true)
            ->defaultThemeMode(ThemeMode::System)
            ->databaseNotifications()
            ->databaseNotificationsPolling('15s')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('partials.filament-theme-variables'))
            ->colors([
                'primary' => Color::Teal,
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Rose,
            ])
            ->tenant(Workspace::class, slugAttribute: 'slug')
            ->tenantRegistration(RegisterWorkspace::class)
            ->tenantProfile(EditWorkspaceSettings::class)
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\\Filament\\App\\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\\Filament\\App\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\\Filament\\App\\Widgets')
            ->widgets([
                WorkspaceHealthPulseWidget::class,
                WorkspaceOverviewWidget::class,
                StorageSavingsRadarWidget::class,
                RecentBackupStreamWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
