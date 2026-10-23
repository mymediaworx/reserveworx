<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\ResolveTenantUnlessPlatform;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

/**
 * Web UI wiring: the Livewire update endpoint runs inside the request's tenant,
 * and membership and platform checks are re-run on every component update.
 */
class UiServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::setUpdateRoute(function ($handle, ?string $path = null) {
            return Route::post($path ?? '/livewire/update', $handle)
                ->middleware(['web', ResolveTenantUnlessPlatform::class]);
        });

        Livewire::addPersistentMiddleware([
            EnsureTenantMembership::class,
            EnsurePlatformAdmin::class,
        ]);

        RateLimiter::for('public-booking', function (Request $request): Limit {
            return Limit::perMinute(20)->by($request->getHost().'|'.$request->ip());
        });
    }
}
