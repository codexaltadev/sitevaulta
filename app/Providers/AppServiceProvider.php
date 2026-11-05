<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super Admin bypasses all permission checks automatically
        Gate::before(function (User $user, string $ability): ?bool {
            if ($user->hasRole('super_admin') || $user->can_access_admin_panel) {
                return true;
            }

            return null;
        });

        SystemSettingsService::injectRuntimeConfig();
    }
}
