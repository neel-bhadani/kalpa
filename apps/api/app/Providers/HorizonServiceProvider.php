<?php

namespace App\Providers;

use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    /**
     * Horizon normally lets anyone in when APP_ENV is local; here the gate below always decides.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request) => Gate::check('viewHorizon', [$request->user()]));
    }

    protected function gate(): void
    {
        // The dashboard is for local development only, and only for workspace owners.
        Gate::define('viewHorizon', function ($user = null) {
            return app()->environment('local')
                && $user !== null
                && WorkspaceMember::where('user_id', $user->id)->where('role', WorkspaceRole::Owner)->exists();
        });
    }
}
