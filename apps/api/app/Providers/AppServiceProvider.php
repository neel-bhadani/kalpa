<?php

namespace App\Providers;

use App\Core\Modules\ModuleRegistry;
use App\Core\Workspaces\CurrentWorkspace;
use App\Models\PersonalAccessToken;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // A fresh one per request.
        $this->app->scoped(CurrentWorkspace::class);

        $this->app->singleton(ModuleRegistry::class, fn () => new ModuleRegistry(config('modules.manifests')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);

        // Lists are { data, meta }: drop Laravel's extra "links" block, the cursors are in meta.
        AnonymousResourceCollection::macro('paginationInformation', fn ($request, $paginated, $default) => [
            'meta' => $default['meta'],
        ]);

        // Only workspace owners turn modules on or off.
        Gate::define('manage-modules', fn () => $this->app->make(CurrentWorkspace::class)->isOwner());

        // 5 sign-in attempts per minute for each email + IP pair.
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip());
        });
    }
}
