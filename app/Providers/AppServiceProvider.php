<?php

namespace App\Providers;

use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Services\Payments\StripeSdkGateway;
use App\Services\Payments\UnconfiguredStripeGateway;
use App\Tenancy\TenantManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
        $this->app->singleton(StripeGateway::class, function (): StripeGateway {
            $secret = config('services.stripe.secret');
            $webhookSecret = config('services.stripe.webhook_secret');

            if (! is_string($secret) || $secret === '' || ! is_string($webhookSecret) || $webhookSecret === '') {
                return new UnconfiguredStripeGateway;
            }

            $version = config('services.stripe.api_version');

            return new StripeSdkGateway(
                $secret,
                $webhookSecret,
                is_string($version) && $version !== '' ? $version : ConnectAccountSpec::API_VERSION,
            );
        });
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('widget', function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) $request->ip());
        });
    }
}
