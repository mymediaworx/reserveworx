<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\EnsureCustomerAccount;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureTenantMembership;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as ApiRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class EveryTenantEndpointIsolationTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    public function test_every_api_route_refuses_another_workspaces_records(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $secret = 'beta-only@cross-tenant.test';

        app(TenantManager::class)->set($beta['tenant']);
        $shop = $this->rentalShop($beta['tenant']);
        $start = CarbonImmutable::parse('2026-11-03 15:00:00', 'UTC');
        $booking = app(Allocator::class)->hold(new AllocationIntent(
            $beta['tenant']->id,
            $shop['service']->id,
            $start,
            $start->addHour(),
            1,
            null,
            'Beta Guest',
            $secret,
        ));
        $booking->load('customer');
        $customerId = (string) $booking->customer_id;
        $this->assertNotSame('', $customerId);
        $this->assertSame($secret, $booking->customer?->email);
        app(TenantManager::class)->forget();

        config([
            'reserveworx.checkout_rate_per_minute' => 1000,
            'reserveworx.availability_rate_per_minute' => 1000,
        ]);

        $before = $this->ledgerCounts();
        $this->actingAsWorkspace($alpha);

        $memberUris = [];
        $unknown = [];

        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1')) {
                continue;
            }

            $kind = $this->kind($route);

            if ($kind === 'unknown') {
                $unknown[] = $route->uri();

                continue;
            }

            if ($kind === 'member') {
                $memberUris[] = $route->uri();
                $this->scanMemberRoute($route, $booking->id, $customerId, $beta['tenant']->id, $beta['membership']->id, $secret);
            } elseif ($kind === 'platform') {
                $this->scanPlatformRoute($route, $booking->id, $customerId, $beta['tenant']->id, $beta['membership']->id, $secret);
            } elseif ($kind === 'customer') {
                $this->scanCustomerRoute($route, $booking->id, $customerId, $beta['tenant']->id, $beta['membership']->id, $secret);
            } elseif ($kind === 'me' || $kind === 'logout') {
                $this->assertRefused(
                    $this->hit('beta.test', $route->methods()[0] === 'HEAD' ? 'GET' : $this->verbs($route)[0], '/'.$route->uri(), []),
                    ['403'],
                    $secret,
                    strtoupper($this->verbs($route)[0]).' /'.$route->uri(),
                );
            } elseif ($kind === 'logo') {
                $this->assertRefused(
                    $this->hit('beta.test', 'GET', '/'.$route->uri(), []),
                    ['200', '404'],
                    $secret,
                    'GET /'.$route->uri(),
                );
            } elseif ($kind === 'download') {
                $path = $this->fill($route->uri(), $booking->id, $customerId, $beta['tenant']->id, $beta['membership']->id);
                $this->assertRefused(
                    $this->hit('beta.test', 'GET', $path, []),
                    ['403'],
                    $secret,
                    'GET '.$path,
                );
            }
        }

        $this->assertSame([], $unknown, 'A new API route was not classified for the cross-tenant scan.');
        $this->assertGreaterThanOrEqual(120, count($memberUris));

        foreach ([
            'api/v1/bookings/{booking}/guide',
            'api/v1/departures/{departure}/cancel',
            'api/v1/corporate-accounts',
            'api/v1/locations/{location}',
            'api/v1/bookings/{booking}/reschedule',
            'api/v1/maintenance-windows/{maintenance_window}/end',
            'api/v1/pools/{pool}/maintenance-records',
        ] as $uri) {
            $this->assertContains($uri, $memberUris);
        }

        $this->assertSame(0, Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->where('user_id', $alpha['user']->id)
            ->count());
        $this->assertSame($before, $this->ledgerCounts());
    }

    private function scanMemberRoute(ApiRoute $route, string $bookingId, string $customerId, string $tenantId, string $fallback, string $secret): void
    {
        $path = $this->fill($route->uri(), $bookingId, $customerId, $tenantId, $fallback);
        $parameterized = str_contains($route->uri(), '{');

        foreach ($this->verbs($route) as $verb) {
            $this->assertRefused(
                $this->hit('beta.test', $verb, $path, $this->staffBody()),
                ['403', '404'],
                $secret,
                $verb.' '.$path.' on beta.test',
            );

            if (! $parameterized && $verb !== 'GET') {
                continue;
            }

            $this->assertRefused(
                $this->hit('alpha.test', $verb, $path, $parameterized ? $this->staffBody() : []),
                $parameterized ? ['403', '404'] : ['200', '403', '404', '422'],
                $secret,
                $verb.' '.$path.' on alpha.test',
            );
        }
    }

    private function scanPlatformRoute(ApiRoute $route, string $bookingId, string $customerId, string $tenantId, string $fallback, string $secret): void
    {
        $path = $this->fill($route->uri(), $bookingId, $customerId, $tenantId, $fallback);

        foreach ($this->verbs($route) as $verb) {
            $response = $this->json($verb, 'http://localhost'.$path, $this->staffBody());
            $this->assertRefused($response, ['403'], $secret, $verb.' '.$path.' on localhost');
        }
    }

    private function scanCustomerRoute(ApiRoute $route, string $bookingId, string $customerId, string $tenantId, string $fallback, string $secret): void
    {
        $path = $this->fill($route->uri(), $bookingId, $customerId, $tenantId, $fallback);
        $accounts = CustomerAccount::withoutGlobalScope(TenantScope::class)->count();

        foreach ($this->verbs($route) as $verb) {
            $response = $this->hit('beta.test', $verb, $path, []);

            if ($this->customerSession($route)) {
                $this->assertRefused($response, ['401', '403', '404'], $secret, $verb.' '.$path);
            } else {
                $this->assertLessThan(500, $response->status(), $verb.' '.$path.' returned '.$response->status());
                $this->assertStringNotContainsString($secret, (string) $response->getContent(), $verb.' '.$path);
            }
        }

        $this->assertSame($accounts, CustomerAccount::withoutGlobalScope(TenantScope::class)->count());
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertRefused(TestResponse $response, array $allowed, string $secret, string $label): void
    {
        $content = (string) $response->getContent();

        $this->assertContains(
            (string) $response->status(),
            $allowed,
            $label.' returned '.$response->status().' '.substr($content, 0, 400),
        );
        $this->assertStringNotContainsString($secret, $content, $label);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function hit(string $host, string $verb, string $path, array $body): TestResponse
    {
        return $this->json($verb, $this->tenantUrl($host, $path), $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function staffBody(): array
    {
        return [
            'hold_minutes' => 30,
            'name' => 'Pat Example',
            'email' => 'pat@example.com',
            'password' => 'correct-horse-battery',
            'role' => 'staff',
            'status' => 'revoked',
            'participants' => 1,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function ledgerCounts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'platform_fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'platform_fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'price_rules' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'customer_accounts' => CustomerAccount::withoutGlobalScope(TenantScope::class)->count(),
            'bookings' => Booking::withoutGlobalScope(TenantScope::class)->count(),
            'customers' => Customer::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }

    private function kind(ApiRoute $route): string
    {
        if ($this->uses($route, 'tenant.member', EnsureTenantMembership::class)) {
            return 'member';
        }

        if ($this->uses($route, 'platform.admin', EnsurePlatformAdmin::class)) {
            return 'platform';
        }

        if (str_starts_with($route->uri(), 'api/v1/customer')) {
            return 'customer';
        }

        return match ($route->uri()) {
            'api/v1/login' => 'login',
            'api/v1/logout' => 'logout',
            'api/v1/me' => 'me',
            'api/v1/stripe/webhook' => 'webhook',
            'api/v1/branding/logo' => 'logo',
            'api/v1/customers/{customer}/exports/{export}/download' => 'download',
            default => 'unknown',
        };
    }

    private function customerSession(ApiRoute $route): bool
    {
        return $this->uses($route, 'auth:customer', EnsureCustomerAccount::class)
            || $this->uses($route, 'customer.account', EnsureCustomerAccount::class);
    }

    private function uses(ApiRoute $route, string $alias, string $class): bool
    {
        foreach (array_merge($route->middleware(), $route->gatherMiddleware()) as $name) {
            $base = explode(':', $name)[0];

            if ($name === $alias || $base === $alias || $base === $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function verbs(ApiRoute $route): array
    {
        return array_values(array_filter(
            $route->methods(),
            fn (string $method): bool => $method !== 'HEAD',
        ));
    }

    private function fill(string $uri, string $bookingId, string $customerId, string $tenantId, string $fallback): string
    {
        $filled = preg_replace_callback('/\{([^}]+)\}/', function (array $matches) use ($bookingId, $customerId, $tenantId, $fallback): string {
            $name = rtrim($matches[1], '?');

            return match ($name) {
                'booking' => $bookingId,
                'customer' => $customerId,
                'tenant' => $tenantId,
                default => $fallback,
            };
        }, $uri);

        return '/'.ltrim((string) $filled, '/');
    }
}
