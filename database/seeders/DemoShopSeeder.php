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
 * Sample shops for the two demo workspaces only. A second run fills anything
 * missing and extends the sunset tour through the next 60 days. It does not
 * read or write any other workspace.
 */
class DemoShopSeeder extends Seeder
{
    private const TOUR_DAYS = 60;

    public function run(): void
    {
        $manager = app(TenantManager::class);

        foreach (['harbor-demo' => 'marina', 'inland-demo' => 'livery'] as $slug => $shop) {
            $tenant = Tenant::query()->where('slug', $slug)->first();

            if (! $tenant instanceof Tenant) {
                $this->command?->line("demo_shop={$slug} skipped=missing");

                continue;
            }

            $manager->set($tenant);

            try {
                $shop === 'marina' ? $this->marina($tenant) : $this->livery($tenant);
                $added = $shop === 'marina' ? $this->ensureTourWindow($tenant) : 0;
                $this->command?->line("demo_shop={$slug} ready=yes departures_added={$added}");
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
        $guide = $this->asset($tenant, $location, 'Sam (guide)', true);

        $kayak = $this->rental($tenant, $location, 'Single kayak rental', $singles, [[60, 2500], [120, 4000], [240, 6500]], 0);
        $this->rental($tenant, $location, 'Tandem kayak rental', $tandems, [[60, 3500], [120, 5500], [240, 8500]], 0, 2);
        $this->rental($tenant, $location, 'Paddleboard rental', $boards, [[60, 2200], [120, 3600]], 0);

        $tour = Service::query()->where('name', 'Sunset paddle tour')->first();

        if (! $tour instanceof Service) {
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
        }

        if (! PromoCode::query()->where('code', 'WELCOME10')->exists()) {
            PromoCode::query()->create([
                'tenant_id' => $tenant->id,
                'code' => 'WELCOME10',
                'percent_off' => 10,
                'active' => true,
            ]);
        }

        if ($kayak->wasRecentlyCreated) {
            $this->bookings($tenant, $kayak, $zone, [
                [1, '09:00', 60, 2, 'Maya Chen', 'confirm'],
                [1, '10:30', 120, 1, 'Luis Ortega', 'confirm'],
                [2, '13:00', 240, 3, 'The Patel family', 'hold'],
                [3, '09:00', 60, 1, 'Jordan Blake', 'confirm'],
            ]);
        }
    }

    private function livery(Tenant $tenant): void
    {
        $zone = 'America/Chicago';
        $location = $this->location($tenant, 'Mill Creek Landing', $zone, '07:00', '18:00');
        $canoes = $this->pool($tenant, $location, 'Canoe', 8);
        $canoe = $this->rental($tenant, $location, 'Canoe float', $canoes, [[180, 4500], [360, 6500]], 0, 3);

        if ($canoe->wasRecentlyCreated) {
            $this->bookings($tenant, $canoe, $zone, [
                [2, '09:00', 180, 2, 'Riley Adams', 'confirm'],
            ]);
        }
    }

    private function ensureTourWindow(Tenant $tenant): int
    {
        $service = Service::query()->where('name', 'Sunset paddle tour')->first();
        $location = Location::query()->where('name', 'Harbor Point Marina')->first();

        if (! $service instanceof Service || ! $location instanceof Location) {
            return 0;
        }

        $today = CarbonImmutable::now('America/New_York')->startOfDay();
        $added = 0;

        for ($day = 1; $day <= self::TOUR_DAYS; $day++) {
            $start = $today->addDays($day)->setTime(18, 0)->utc();

            if (Departure::query()->where('service_id', $service->id)->where('starts_at', $start)->exists()) {
                continue;
            }

            Departure::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $service->id,
                'location_id' => $location->id,
                'starts_at' => $start,
                'ends_at' => $start->addHours(2),
                'private' => false,
                'seats' => 10,
                'status' => DepartureStatus::Scheduled,
            ]);
            $added++;
        }

        return $added;
    }

    private function location(Tenant $tenant, string $name, string $zone, string $opens, string $closes): Location
    {
        $location = Location::query()->where('name', $name)->first();

        if (! $location instanceof Location) {
            $location = Location::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'timezone' => $zone,
                'status' => LocationStatus::Active,
            ]);
        }

        foreach (range(0, 6) as $weekday) {
            $exists = AvailabilitySchedule::query()
                ->where('owner_type', ScheduleOwnerType::Location)
                ->where('owner_id', $location->id)
                ->where('weekday', $weekday)
                ->exists();

            if ($exists) {
                continue;
            }

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
        $pool = Pool::query()->where('location_id', $location->id)->where('name', $name)->first();

        if ($pool instanceof Pool) {
            return $pool;
        }

        return Pool::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'quantity' => $quantity,
            'status' => ResourceStatus::Active,
            'turnaround_minutes' => 15,
        ]);
    }

    private function asset(Tenant $tenant, Location $location, string $name, bool $guide): Asset
    {
        $asset = Asset::query()->where('location_id', $location->id)->where('name', $name)->first();

        if ($asset instanceof Asset) {
            return $asset;
        }

        return Asset::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'status' => ResourceStatus::Active,
            'capacity' => 1,
            'is_guide' => $guide,
        ]);
    }

    /**
     * @param  list<array{0: int, 1: int}>  $lengths  minutes and price in cents
     */
    private function rental(Tenant $tenant, Location $location, string $name, Pool $pool, array $lengths, int $perPersonCents, int $perUnit = 1): Service
    {
        $existing = Service::query()->where('name', $name)->first();

        if ($existing instanceof Service) {
            return $existing;
        }

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
