<?php

namespace App\Providers;

use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Services\Branding\CurrentWorkspaceBranding;
use App\Services\Branding\WorkspaceProfiles;
use App\Services\Payments\StripeSdkGateway;
use App\Services\Payments\UnconfiguredStripeGateway;
use App\Services\Webhooks\HostAddressLookup;
use App\Services\Webhooks\SystemHostAddressLookup;
use App\Services\Webhooks\WebhookPublisher;
use App\Tenancy\TenantManager;
use App\View\Composers\WorkspaceBrandingComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantManager::class);
        $this->app->singleton(CurrentWorkspaceBranding::class);
        $this->app->singleton(HostAddressLookup::class, SystemHostAddressLookup::class);
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
        View::composer('*', WorkspaceBrandingComposer::class);

        Tenant::created(function (Tenant $tenant): void {
            app(WorkspaceProfiles::class)->provision($tenant);
        });

        Payment::saved(function (Payment $payment): void {
            if ($payment->status !== 'paid' || (! $payment->wasRecentlyCreated && ! $payment->wasChanged('status'))) {
                return;
            }

            $context = app(TenantManager::class)->id();

            if ($context === null || $context !== $payment->tenant_id) {
                return;
            }

            app(WebhookPublisher::class)->paymentSucceeded($payment);
        });

        Refund::created(function (Refund $refund): void {
            $context = app(TenantManager::class)->id();

            if ($context === null || $context !== $refund->tenant_id) {
                return;
            }

            app(WebhookPublisher::class)->refundCreated($refund);
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = strtolower((string) $request->input('email'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->ip());
        });

        RateLimiter::for('widget', function (Request $request): Limit {
            return Limit::perMinute(30)->by((string) $request->ip());
        });

        RateLimiter::for('availability', function (Request $request): Limit {
            return Limit::perMinute(self::ratePerMinute('reserveworx.availability_rate_per_minute', 60))
                ->by(self::rateLimitKey($request));
        });

        RateLimiter::for('checkout', function (Request $request): Limit {
            return Limit::perMinute(self::ratePerMinute('reserveworx.checkout_rate_per_minute', 10))
                ->by(self::rateLimitKey($request));
        });
    }

    private static function ratePerMinute(string $key, int $default): int
    {
        $value = config($key, $default);

        if (! is_int($value) && ! is_string($value) && ! is_float($value)) {
            return $default;
        }

        return max(1, (int) $value);
    }

    private static function rateLimitKey(Request $request): string
    {
        $user = $request->user();
        $id = $user !== null ? (string) $user->getAuthIdentifier() : 'guest';

        return $id.'|'.$request->ip();
    }
}
