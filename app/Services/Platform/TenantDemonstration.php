<?php

namespace App\Services\Platform;

use App\Models\Booking;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\StaffLocationScope;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;

/**
 * Read-only proof that the two seeded demo workspaces keep their own records.
 * Spec Section 17 and Spec Section 15 phase 7. Nothing here creates a workspace
 * or writes a booking, price, payment, tax, fee, or refund.
 */
final class TenantDemonstration
{
    /**
     * Slugs from DemoTenantSeeder. The report does not read fee settings.
     *
     * @var list<string>
     */
    private const SLUGS = ['harbor-demo', 'inland-demo'];

    public function __construct(private readonly TenantManager $tenants) {}

    /**
     * @return array{
     *     shown: bool,
     *     summary: string,
     *     shops: list<array{
     *         slug: string,
     *         state: string,
     *         name: string,
     *         status: string,
     *         hostname: string,
     *         locations: int,
     *         services: int,
     *         bookings: int,
     *         isolated: string,
     *         detail: string,
     *         line: string
     *     }>
     * }
     */
    public function build(): array
    {
        $previous = $this->tenants->get();

        try {
            $loaded = [];

            foreach (self::SLUGS as $slug) {
                $loaded[$slug] = Tenant::query()->where('slug', $slug)->first();
            }

            $shops = [];

            foreach (self::SLUGS as $index => $slug) {
                $otherSlug = self::SLUGS[$index === 0 ? 1 : 0];
                $shops[] = $this->shop($slug, $loaded[$slug], $loaded[$otherSlug]);
            }

            $shown = true;

            foreach ($shops as $shop) {
                if ($shop['state'] !== 'present' || $shop['isolated'] !== 'yes') {
                    $shown = false;
                }
            }

            return [
                'shown' => $shown,
                'summary' => $shown
                    ? 'Both demo workspaces are loaded. A record stored on one is not listed for the other.'
                    : 'The demonstration needs both demo workspaces, and neither may list the other\'s records.',
                'shops' => $shops,
            ];
        } finally {
            if ($previous instanceof Tenant) {
                $this->tenants->set($previous);
            } else {
                $this->tenants->forget();
            }
        }
    }

    /**
     * @return array{
     *     slug: string,
     *     state: string,
     *     name: string,
     *     status: string,
     *     hostname: string,
     *     locations: int,
     *     services: int,
     *     bookings: int,
     *     isolated: string,
     *     detail: string,
     *     line: string
     * }
     */
    private function shop(string $slug, ?Tenant $tenant, ?Tenant $other): array
    {
        if (! $tenant instanceof Tenant) {
            return [
                'slug' => $slug,
                'state' => 'missing',
                'name' => $slug,
                'status' => 'none',
                'hostname' => 'none',
                'locations' => 0,
                'services' => 0,
                'bookings' => 0,
                'isolated' => 'no',
                'detail' => 'This demo workspace is not loaded. Opening this page does not create it.',
                'line' => $slug.'=missing',
            ];
        }

        $counts = $this->counts($tenant, $other);
        $name = $this->label($tenant->name);
        $status = $tenant->status->value;
        $hostname = $this->hostname($tenant);
        $isolated = $counts['isolated'] ? 'yes' : 'no';
        $detail = $this->detail($other, $counts['isolated']);

        return [
            'slug' => $slug,
            'state' => 'present',
            'name' => $name,
            'status' => $status,
            'hostname' => $hostname,
            'locations' => $counts['locations'],
            'services' => $counts['services'],
            'bookings' => $counts['bookings'],
            'isolated' => $isolated,
            'detail' => $detail,
            'line' => $slug.'=present name='.$name.' status='.$status.' hostname='.$hostname
                .' locations='.$counts['locations'].' services='.$counts['services']
                .' bookings='.$counts['bookings'].' isolated='.$isolated,
        ];
    }

    /**
     * @return array{locations: int, services: int, bookings: int, isolated: bool}
     */
    private function counts(Tenant $tenant, ?Tenant $other): array
    {
        $this->tenants->set($tenant);
        $locations = $this->measure(Location::class, $tenant, $other);
        $services = $this->measure(Service::class, $tenant, $other);
        $bookings = $this->measure(Booking::class, $tenant, $other);
        $isolated = $other instanceof Tenant
            && $locations['separated']
            && $services['separated']
            && $bookings['separated'];

        return [
            'locations' => $locations['own'],
            'services' => $services['own'],
            'bookings' => $bookings['own'],
            'isolated' => $isolated,
        ];
    }

    /**
     * @param  class-string<Location|Service|Booking>  $model
     * @return array{own: int, separated: bool}
     */
    private function measure(string $model, Tenant $tenant, ?Tenant $other): array
    {
        $scoped = $model::query()->withoutGlobalScope(StaffLocationScope::class)->count();
        $own = $model::query()
            ->withoutGlobalScope(TenantScope::class)
            ->withoutGlobalScope(StaffLocationScope::class)
            ->where('tenant_id', $tenant->id)
            ->count();
        $foreign = 0;

        if ($other instanceof Tenant) {
            $foreign = $model::query()
                ->withoutGlobalScope(StaffLocationScope::class)
                ->where('tenant_id', $other->id)
                ->count();
        }

        return [
            'own' => $own,
            'separated' => $scoped === $own && $foreign === 0,
        ];
    }

    private function detail(?Tenant $other, bool $isolated): string
    {
        if (! $other instanceof Tenant) {
            return 'The other demo workspace is not loaded, so these records were not compared.';
        }

        if (! $isolated) {
            return 'A location, service, or booking from the other demo workspace is listed here.';
        }

        return 'Locations, services, and bookings stored here stay here. The other demo workspace does not list them.';
    }

    private function label(string $name): string
    {
        $clean = preg_replace('/[\r\n=]+/', ' ', $name) ?? '';
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? '');

        if ($clean === '') {
            return 'unnamed';
        }

        return mb_substr($clean, 0, 80);
    }

    private function hostname(Tenant $tenant): string
    {
        $names = $tenant->domains()
            ->whereNotNull('verified_at')
            ->orderBy('hostname')
            ->pluck('hostname');
        $safe = [];

        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }

            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+\z/i', $name) !== 1) {
                continue;
            }

            $safe[] = strtolower($name);

            if (count($safe) === 3) {
                break;
            }
        }

        return $safe === [] ? 'none' : implode(',', $safe);
    }
}
