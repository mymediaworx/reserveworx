<?php

namespace Database\Seeders;

use App\Enums\DepartureStatus;
use App\Enums\LocationStatus;
use App\Enums\ResourceStatus;
use App\Enums\ScheduleOwnerType;
use App\Models\Asset;
use App\Models\AvailabilitySchedule;
use App\Models\Departure;
use App\Models\Location;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\PromoCode;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Gives the two demo tenants something to look at: a marina with kayaks,
 * paddleboards, and a guided tour, and a small inland canoe livery. Runs only
 * for demo tenants that have no locations yet, so it never touches real data
 * and is safe to run again.
 */
class DemoShopSeeder extends Seeder
{
    public function run(): void
    {
        $manager = app(TenantManager::class);

        foreach (['harbor-demo' => 'marina', 'inland-demo' => 'livery'] as $slug => $shop) {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant instanceof Tenant) {
                continue;
            }

            $manager->set($tenant);

            try {
                if (Location::query()->exists()) {
                    $this->command?->line("demo_shop={$slug} skipped=has_locations");

                    continue;
                }

                $shop === 'marina' ? $this->marina($tenant) : $this->livery($tenant);
                $this->command?->line("demo_shop={$slug} seeded=yes");
            } finally {
                $manager->forget();
            }
        }
    }

    private function marina(Tenant $tenant): void
    {
        $zone = 'America/New_York';
        $location = $this->location($tenant, 'Harbor Point Marina', $zone, '08:00', '20:00');
        $singles = $this->pool($tenant, $location, 'Single kayak', 10);
        $tandems = $this->pool($tenant, $location, 'Tandem kayak', 6);
        $boards = $this->pool($tenant, $location, 'Paddleboard', 8);
        $guide = Asset::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Sam (guide)',
            'status' => ResourceStatus::Active,
            'capacity' => 1,
            'is_guide' => true,
        ]);

        $kayak = $this->rental($tenant, $location, 'Single kayak rental', $singles, [[60, 2500], [120, 4000], [240, 6500]], 0);
        $this->rental($tenant, $location, 'Tandem kayak rental', $tandems, [[60, 3500], [120, 5500], [240, 8500]], 0, 2);
        $this->rental($tenant, $location, 'Paddleboard rental', $boards, [[60, 2200], [120, 3600]], 0);

        $tour = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Sunset paddle tour',
            'kind' => 'tour',
            'min_participants' => 1,
            'max_participants' => 10,
            'waitlist_enabled' => true,
        ]);
        $this->requirement($tenant, $tour, 'pool', $singles->id, 1, 1);
        $this->requirement($tenant, $tour, 'asset', $guide->id, 1, null);
        $this->price($tenant, $tour, 'Per paddler', 'per_person', 5500);

        $today = CarbonImmutable::now($zone)->startOfDay();

        for ($day = 1; $day <= 14; $day++) {
            $start = $today->addDays($day)->setTime(18, 0)->utc();
            Departure::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $tour->id,
                'location_id' => $location->id,
                'starts_at' => $start,
                'ends_at' => $start->addHours(2),
                'private' => false,
                'seats' => 10,
                'status' => DepartureStatus::Scheduled,
            ]);
        }

        PromoCode::query()->create([
            'tenant_id' => $tenant->id,
            'code' => 'WELCOME10',
            'percent_off' => 10,
            'active' => true,
        ]);

        $this->bookings($tenant, $kayak, $zone, [
            [1, '09:00', 60, 2, 'Maya Chen', 'confirm'],
            [1, '10:30', 120, 1, 'Luis Ortega', 'confirm'],
            [2, '13:00', 240, 3, 'The Patel family', 'hold'],
            [3, '09:00', 60, 1, 'Jordan Blake', 'confirm'],
        ]);
    }

    private function livery(Tenant $tenant): void
    {
        $zone = 'America/Chicago';
        $location = $this->location($tenant, 'Mill Creek Landing', $zone, '07:00', '18:00');
        $canoes = $this->pool($tenant, $location, 'Canoe', 8);
        $canoe = $this->rental($tenant, $location, 'Canoe float', $canoes, [[180, 4500], [360, 6500]], 0, 3);
        $this->bookings($tenant, $canoe, $zone, [
            [2, '09:00', 180, 2, 'Riley Adams', 'confirm'],
        ]);
    }

    private function location(Tenant $tenant, string $name, string $zone, string $opens, string $closes): Location
    {
        $location = Location::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'timezone' => $zone,
            'status' => LocationStatus::Active,
        ]);

        foreach (range(0, 6) as $weekday) {
            AvailabilitySchedule::query()->create([
                'tenant_id' => $tenant->id,
                'owner_type' => ScheduleOwnerType::Location,
                'owner_id' => $location->id,
                'weekday' => $weekday,
                'opens_local' => $opens.':00',
                'closes_local' => $closes.':00',
            ]);
        }

        return $location;
    }

    private function pool(Tenant $tenant, Location $location, string $name, int $quantity): Pool
    {
        return Pool::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'quantity' => $quantity,
            'status' => ResourceStatus::Active,
            'turnaround_minutes' => 15,
        ]);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $lengths  minutes and price in cents
     */
    private function rental(Tenant $tenant, Location $location, string $name, Pool $pool, array $lengths, int $perPersonCents, int $perUnit = 1): Service
    {
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => 'rental',
            'min_participants' => 1,
            'max_participants' => 12,
            'waitlist_enabled' => false,
        ]);
        $this->requirement($tenant, $service, 'pool', $pool->id, 1, $perUnit);

        foreach ($lengths as [$minutes, $cents]) {
            $label = $minutes % 60 === 0 ? ($minutes / 60).' '.($minutes === 60 ? 'hour' : 'hours') : $minutes.' minutes';
            ServiceDuration::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $service->id,
                'name' => $label,
                'quantity' => $minutes % 60 === 0 ? intdiv($minutes, 60) : $minutes,
                'unit' => $minutes % 60 === 0 ? 'hours' : 'minutes',
                'duration_minutes' => $minutes,
            ]);
            PriceRule::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $service->id,
                'name' => $label,
                'kind' => 'duration',
                'amount_cents' => $cents,
                'window' => 'always',
                'duration_minutes' => $minutes,
            ]);
        }

        if ($perPersonCents > 0) {
            $this->price($tenant, $service, 'Per paddler', 'per_person', $perPersonCents);
        }

        return $service;
    }

    private function requirement(Tenant $tenant, Service $service, string $type, string $id, int $quantity, ?int $per): void
    {
        ResourceRequirement::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'resource_type' => $type,
            'resource_id' => $id,
            'quantity' => $quantity,
            'per_participants' => $per,
        ]);
    }

    private function price(Tenant $tenant, Service $service, string $name, string $kind, int $cents): void
    {
        PriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => $name,
            'kind' => $kind,
            'amount_cents' => $cents,
            'window' => 'always',
        ]);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int, 3: int, 4: string, 5: string}>  $rows
     */
    private function bookings(Tenant $tenant, Service $service, string $zone, array $rows): void
    {
        $allocator = app(Allocator::class);
        $today = CarbonImmutable::now($zone)->startOfDay();

        foreach ($rows as [$day, $time, $minutes, $people, $name, $action]) {
            [$hour, $minute] = array_map('intval', explode(':', $time));
            $start = $today->addDays($day)->setTime($hour, $minute)->utc();

            try {
                $booking = $allocator->hold(new AllocationIntent(
                    $tenant->id,
                    $service->id,
                    $start,
                    $start->addMinutes($minutes),
                    $people,
                    'demo-'.$tenant->slug.'-'.$day.'-'.$time,
                    $name,
                    null,
                ));

                if ($action === 'confirm') {
                    $allocator->confirm($booking);
                }
            } catch (AllocationUnavailableException) {
                // Demo data is best effort; a busy slot is simply skipped.
            }
        }
    }
}
