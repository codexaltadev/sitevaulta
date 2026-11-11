<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Settings\SystemSettingsService;
use Illuminate\Foundation\DevCommands;
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

        // `composer run dev` also runs the scheduler and the uptime-check worker (production uses Supervisor).
        if ($this->app->runningInConsole() && $this->app->isLocal()) {
            DevCommands::artisan('schedule:work', 'scheduler');
            DevCommands::artisan('queue:listen --queue='.config('monitoring.queue', 'monitoring').' --tries=1 --timeout=0', 'monitors');
        }
    }
}
