<?php

namespace Tests\Support;

use App\Models\Location;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Tenancy\TenantManager;

/**
 * A small bookable shop: one location, a pool of kayaks, and an hourly rental
 * priced at $25 per booking plus $10 per person.
 */
trait BuildsRentalShop
{
    /**
     * @return array{location: Location, pool: Pool, service: Service}
     */
    protected function rentalShop(Tenant $tenant, int $kayaks = 4, string $timezone = 'America/New_York'): array
    {
        $manager = app(TenantManager::class);
        $previous = $manager->get();
        $manager->set($tenant);

        $location = Location::factory()->create(['tenant_id' => $tenant->id, 'name' => 'North dock', 'timezone' => $timezone]);
        $pool = Pool::factory()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Single kayak',
            'quantity' => $kayaks,
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => 'rental',
        ]);
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => 'pool',
            'resource_id' => $pool->id,
            'quantity' => 1,
            'per_participants' => 1,
        ]);
        ServiceDuration::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => '1 hour',
            'quantity' => 1,
            'unit' => 'hours',
            'duration_minutes' => 60,
        ]);
        PriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => 'Launch',
            'kind' => 'base',
            'amount_cents' => 2500,
            'window' => 'always',
        ]);
        PriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => 'Per paddler',
            'kind' => 'per_person',
            'amount_cents' => 1000,
            'window' => 'always',
        ]);

        $previous ? $manager->set($previous) : $manager->forget();

        return ['location' => $location, 'pool' => $pool, 'service' => $service->fresh(['location', 'durations'])];
    }
}
