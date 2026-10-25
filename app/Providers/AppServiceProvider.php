<?php

namespace App\Providers;

use App\Auth\CustomerTokenGuard;
use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use App\Enums\OperationalStatus;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Tenant;
use App\Services\Branding\CurrentWorkspaceBranding;
use App\Services\Branding\WorkspaceProfiles;
use App\Services\Feedback\FeedbackRequests;
use App\Services\Integrations\GhlSync;
use App\Services\Integrations\GoHighLevelClient;
use App\Services\Payments\StripeSdkGateway;
use App\Services\Payments\UnconfiguredStripeGateway;
use App\Services\Webhooks\HostAddressLookup;
use App\Services\Webhooks\SystemHostAddressLookup;
use App\Services\Webhooks\WebhookPublisher;
use App\Tenancy\TenantManager;
use App\View\Composers\IndustryComposer;
use App\View\Composers\WorkspaceBrandingComposer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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

        $this->app->singleton(GoHighLevelClient::class, function (): GoHighLevelClient {
            $base = config('services.ghl.base_url');
            $version = config('services.ghl.version');

            return new GoHighLevelClient(
                is_string($base) && $base !== '' ? rtrim($base, '/') : 'https://services.leadconnectorhq.com',
                is_string($version) && $version !== '' ? $version : GoHighLevelClient::VERSION,
            );
        });
    }

    public function boot(): void
    {
        View::composer('*', WorkspaceBrandingComposer::class);
        View::composer(['layouts.staff', 'livewire.staff.*', 'staff.*'], IndustryComposer::class);

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

        app(GhlSync::class)->register();

        Booking::updated(function (Booking $booking): void {
            if (! $booking->wasChanged('operational_status')) {
                return;
            }

            $status = $booking->operational_status;

            if ($status !== OperationalStatus::Returned && $status !== OperationalStatus::Completed) {
                return;
            }

            app(FeedbackRequests::class)->queue($booking);
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

        RateLimiter::for('customer-auth', function (Request $request): array {
            $email = strtolower((string) ($request->input('email') ?? $request->query('email')));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(20)->by('ip|'.$request->ip()),
            ];
        });

        RateLimiter::for('customer-api', function (Request $request): Limit {
            return Limit::perMinute(60)->by(self::rateLimitKey($request));
        });

        RateLimiter::for('signup', function (Request $request): Limit {
            return Limit::perMinute(self::ratePerMinute('reserveworx.signup_rate_per_minute', 5))
                ->by('signup|'.$request->ip());
        });

        RateLimiter::for('signup-availability', function (Request $request): Limit {
            return Limit::perMinute(30)->by('signup-availability|'.$request->ip());
        });

        RateLimiter::for('signup-resend', function (Request $request): Limit {
            return Limit::perMinute(5)->by('signup-resend|'.$request->ip());
        });

        RateLimiter::for('signup-verify', function (Request $request): Limit {
            return Limit::perMinute(10)->by('signup-verify|'.$request->ip());
        });

        RateLimiter::for('feedback', function (Request $request): Limit {
            return Limit::perMinute(30)->by('feedback|'.$request->ip());
        });

        RateLimiter::for('invite-accept', function (Request $request): Limit {
            return Limit::perMinute(10)->by('invite-accept|'.$request->ip());
        });

        Auth::extend('customer-token', function ($app, string $name, array $config): CustomerTokenGuard {
            if (! $app instanceof Application) {
                throw new \RuntimeException('The customer guard needs the application.');
            }

            $guard = new CustomerTokenGuard($app->make('request'));
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
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
