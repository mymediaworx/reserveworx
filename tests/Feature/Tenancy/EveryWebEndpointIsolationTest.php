<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsurePlatformHost;
use App\Http\Middleware\EnsureTenantMembership;
use App\Http\Middleware\ResolveTenant;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\CustomerAccount;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\Allocator;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as WebRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class EveryWebEndpointIsolationTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    private int $address = 0;

    public function test_every_web_route_hides_another_workspaces_customer(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $secret = 'beta-only@cross-tenant.test';
        $guest = 'Beta Only Guest';

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
            $guest,
            $secret,
        ));
        $booking->load('customer');
        $widget = app(WidgetTokens::class)->hostedPlain($beta['tenant']);
        app(TenantManager::class)->forget();

        $this->assertSame($secret, $booking->customer?->email);
        $status = $booking->status->value ?? (string) $booking->status;
        $before = $this->ledgerCounts();
        $betaAudits = $this->auditCount($beta['tenant']->id);

        $ids = [
            'booking' => $booking->id,
            'customer' => (string) $booking->customer_id,
            'tenant' => $beta['tenant']->id,
            'membership' => $beta['membership']->id,
            'service' => $shop['service']->id,
            'location' => $shop['location']->id,
            'pool' => $shop['pool']->id,
            'user' => $beta['user']->id,
            'widget' => $widget,
        ];
        $platform = (string) config('reserveworx.platform_domains.0');
        $hosts = ['alpha.test', 'beta.test', $platform];
        $scanned = [];
        $logouts = [];

        $this->actingAs($alpha['user']);

        foreach (Route::getRoutes() as $route) {
            if (! $this->applicationRoute($route)) {
                continue;
            }

            if ($this->isLogout($route)) {
                $logouts[] = $route;

                continue;
            }

            $scanned[] = $route->uri();
            $path = $this->fill($route, $ids);

            foreach ($this->verbs($route) as $verb) {
                foreach ($hosts as $host) {
                    $this->assertIsolated(
                        $this->hit($host, $verb, $path),
                        $route,
                        $host,
                        $platform,
                        $secret,
                        $guest,
                        strtoupper($verb).' '.$path.' on '.$host,
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(80, count($scanned), 'The web scan did not see the application routes.');
        foreach ([
            'staff/bookings/{booking}',
            'book/{token}',
            'book/{token}/services/{service}',
            'platform/tenants/{tenant}',
            'account',
            'waivers/{booking}',
            'staff/logout',
        ] as $uri) {
            if ($uri === 'staff/logout') {
                $this->assertTrue(collect($logouts)->contains(fn (WebRoute $route): bool => $route->uri() === $uri));

                continue;
            }

            $this->assertContains($uri, $scanned);
        }

        $this->assertSame($before, $this->ledgerCounts());
        $this->assertSame($betaAudits, $this->auditCount($beta['tenant']->id));
        $this->assertSame($status, $this->freshStatus($booking));
        $this->assertSame(0, Membership::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->where('user_id', $alpha['user']->id)
            ->count());
        $this->assertNotEmpty($logouts);

        foreach ($logouts as $route) {
            $path = $this->fill($route, $ids);

            foreach ($this->verbs($route) as $verb) {
                foreach ($hosts as $host) {
                    $this->actingAs($alpha['user']);
                    $this->assertIsolated(
                        $this->hit($host, $verb, $path),
                        $route,
                        $host,
                        $platform,
                        $secret,
                        $guest,
                        strtoupper($verb).' '.$path.' on '.$host,
                    );
                }
            }
        }

        $after = $this->ledgerCounts();
        unset($before['audits'], $after['audits']);
        $this->assertSame($before, $after);
        $this->assertSame($betaAudits, $this->auditCount($beta['tenant']->id));
        $this->assertSame($status, $this->freshStatus($booking));
    }

    public function test_signing_out_of_another_workspace_does_not_audit_that_workspace(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $own = $this->auditCount($alpha['tenant']->id);
        $before = $this->auditCount($beta['tenant']->id);

        $this->actingAs($alpha['user'])
            ->post($this->tenantUrl('alpha.test', '/staff/logout'))
            ->assertRedirect('/staff/login');

        $this->assertSame($own + 1, $this->auditCount($alpha['tenant']->id));

        $this->actingAs($alpha['user'])
            ->post($this->tenantUrl('beta.test', '/staff/logout'))
            ->assertRedirect('/staff/login');

        $this->assertGuest();
        $this->assertSame($before, $this->auditCount($beta['tenant']->id));
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $beta['tenant']->id)
            ->where('action', 'auth.logout')
            ->count());
    }

    private function assertIsolated(
        TestResponse $response,
        WebRoute $route,
        string $host,
        string $platform,
        string $secret,
        string $guest,
        string $label,
    ): void {
        $content = (string) $response->getContent();
        $location = (string) $response->headers->get('Location');
        $status = $response->getStatusCode();

        $this->assertLessThan(500, $status, $label.' returned '.$status.' '.substr($content, 0, 300));
        $this->assertFalse(str_contains($content, $secret), $label.' included the other customer email');
        $this->assertFalse(str_contains($content, $guest), $label.' included the other customer name');
        $this->assertFalse(str_contains($location, $secret) || str_contains($location, rawurlencode($guest)), $label.' redirected with the other customer');

        if ($this->uses($route, 'platform.host', EnsurePlatformHost::class) && $host !== $platform) {
            $this->assertSame(404, $status, $label);

            return;
        }

        if ($this->uses($route, 'tenant.resolve', ResolveTenant::class) && $host === $platform) {
            $this->assertSame(404, $status, $label);

            return;
        }

        if ($this->uses($route, 'platform.admin', EnsurePlatformAdmin::class) && $host === $platform) {
            $this->assertSame(403, $status, $label);

            return;
        }

        if (! $this->uses($route, 'tenant.member', EnsureTenantMembership::class) || $host === $platform) {
            return;
        }

        if ($host !== 'alpha.test') {
            $this->assertContains($status, [403, 404], $label.' returned '.$status);

            return;
        }

        if ($this->namesOtherWorkspace($route)) {
            $this->assertContains($status, [302, 403, 404, 422], $label.' returned '.$status);
        }
    }

    private function hit(string $host, string $verb, string $path): TestResponse
    {
        return $this->call($verb, $this->tenantUrl($host, $path), [], [], [], $this->server());
    }

    /**
     * @return array<string, string>
     */
    private function server(): array
    {
        $this->address++;
        $address = $this->address;

        return [
            'REMOTE_ADDR' => sprintf('%d.%d.%d.%d', 10, ($address >> 16) & 255, ($address >> 8) & 255, $address & 255),
            'HTTP_ACCEPT' => 'text/html',
        ];
    }

    private function applicationRoute(WebRoute $route): bool
    {
        if (str_starts_with($route->uri(), 'api/')) {
            return false;
        }

        $action = $route->getActionName();

        return str_starts_with($action, 'App\\')
            || $action === 'Illuminate\Routing\RedirectController'
            || $route->uri() === 'livewire/update';
    }

    private function namesOtherWorkspace(WebRoute $route): bool
    {
        return preg_match('/\{(booking|customer|tenant|membership|service|location|pool|user|export|signature|participant|incident|photo|waitlistEntry|ghl_sync_log|ghl_message_log)/', $route->uri()) === 1;
    }

    private function isLogout(WebRoute $route): bool
    {
        return str_ends_with($route->uri(), 'logout');
    }

    private function uses(WebRoute $route, string $alias, string $class): bool
    {
        foreach (array_merge($route->middleware(), $route->gatherMiddleware()) as $name) {
            $base = explode(':', (string) $name)[0];

            if ($name === $alias || $base === $alias || $base === $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function verbs(WebRoute $route): array
    {
        return array_values(array_filter(
            $route->methods(),
            fn (string $method): bool => $method !== 'HEAD',
        ));
    }

    /**
     * @param  array{
     *     booking: string,
     *     customer: string,
     *     tenant: string,
     *     membership: string,
     *     service: string,
     *     location: string,
     *     pool: string,
     *     user: string,
     *     widget: string
     * }  $ids
     */
    private function fill(WebRoute $route, array $ids): string
    {
        $uri = $route->uri();
        $filled = preg_replace_callback('/\{([^}]+)\}/', function (array $matches) use ($route, $uri, $ids): string {
            $name = rtrim($matches[1], '?');

            if (str_starts_with($uri, 'book/') && $name === 'token') {
                return $ids['widget'];
            }

            return match ($name) {
                'booking' => $ids['booking'],
                'customer' => $ids['customer'],
                'tenant' => $ids['tenant'],
                'membership' => $ids['membership'],
                'service' => $ids['service'],
                'location' => $ids['location'],
                'pool' => $ids['pool'],
                'user' => $ids['user'],
                'type' => 'customers',
                default => $this->constrained((string) ($route->wheres[$name] ?? '')),
            };
        }, $uri);

        return '/'.ltrim((string) $filled, '/');
    }

    private function constrained(string $where): string
    {
        return match ($where) {
            '[A-Za-z0-9]{64}', '[a-f0-9]{64}' => str_repeat('a', 64),
            '[A-Za-z0-9]{32,128}' => str_repeat('b', 40),
            '[a-f0-9]{40}' => str_repeat('c', 40),
            'customers|inventory' => 'customers',
            default => (string) Str::ulid(),
        };
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
            'location_taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
            'bookings' => Booking::withoutGlobalScope(TenantScope::class)->count(),
            'customers' => Customer::withoutGlobalScope(TenantScope::class)->count(),
            'customer_accounts' => CustomerAccount::withoutGlobalScope(TenantScope::class)->count(),
            'audits' => AuditLog::withoutGlobalScope(TenantScope::class)->count(),
            'tenants' => Tenant::query()->count(),
            'users' => User::query()->count(),
        ];
    }

    private function auditCount(string $tenantId): int
    {
        return AuditLog::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId)->count();
    }

    private function freshStatus(Booking $booking): string
    {
        $fresh = Booking::withoutGlobalScope(TenantScope::class)->findOrFail($booking->id);
        $status = $fresh->status;

        return $status instanceof \BackedEnum ? $status->value : (string) $status;
    }
}
