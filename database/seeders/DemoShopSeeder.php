<?php

namespace Database\Seeders;

use App\Domain\Fees\FeeChannel;
use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Enums\BookingStatus;
use App\Enums\CancellationPolicyKind;
use App\Enums\DepartureStatus;
use App\Enums\LocationStatus;
use App\Enums\ResourceStatus;
use App\Enums\ScheduleOwnerType;
use App\Models\AddOn;
use App\Models\Asset;
use App\Models\AvailabilitySchedule;
use App\Models\Booking;
use App\Models\CancellationPolicy;
use App\Models\Departure;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\PromoCode;
use App\Models\RentalCheckout;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\WaiverVersion;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Pricing\BookingPricer;
use App\Services\Tenancy\DemoWorkspaceIdentity;
use App\Services\Waivers\WaiverSigner;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use InvalidArgumentException;

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
                app(DemoWorkspaceIdentity::class)->align($tenant);
                $this->enablePayAtPickup($tenant);
                $shop === 'marina' ? $this->marina($tenant) : $this->livery($tenant);
                $added = $shop === 'marina' ? $this->ensureTourWindow($tenant) : 0;

                if ($shop === 'marina') {
                    $this->harborSamples($tenant);
                }
                $this->command?->line("demo_shop={$slug} ready=yes departures_added={$added}");
            } finally {
                $manager->forget();
            }
        }
    }

    private function enablePayAtPickup(Tenant $tenant): void
    {
        $setting = TenantSetting::query()->where('tenant_id', $tenant->id)->first();

        if (! $setting instanceof TenantSetting) {
            TenantSetting::query()->create([
                'tenant_id' => $tenant->id,
                'pay_at_pickup' => true,
            ]);

            return;
        }

        if ($setting->pay_at_pickup) {
            return;
        }

        $setting->forceFill(['pay_at_pickup' => true])->save();
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

    private const DEMO_WAIVER = 'Harbor demo waiver';

    private const DEMO_CHECKOUT_KEY = 'demo-harbor-demo-checkout';

    private const DEMO_MULTIDAY_KEY = 'demo-harbor-demo-multiday';

    private const DEMO_MULTIDAY_MINUTES = 2880;

    private const DEMO_GUEST_NAME = 'Avery Cole';

    private const DEMO_GUEST_EMAIL = 'avery.cole@harbor-demo.test';

    /**
     * Harbor demo only (approved sample values): a waiver, add-ons, a one-day
     * length, a cancellation and no-show policy, a sample tax rate, one
     * rental that is out and due back a few hours from now, and one confirmed
     * two-day rental that stays at least seven days ahead. Every step checks
     * for its own row first, so reruns change nothing except rolling those
     * two demo rentals forward.
     */
    private function harborSamples(Tenant $tenant): void
    {
        if ($tenant->slug !== 'harbor-demo') {
            return;
        }

        if (! WaiverVersion::query()->where('name', self::DEMO_WAIVER)->exists()) {
            app(WaiverSigner::class)->publish($tenant->id, self::DEMO_WAIVER, 'Sample waiver for the Harbor demo shop. I understand paddling on open water carries risk, I will wear the life jacket provided, and I will follow the guide and staff instructions. This is demo text only.');
        }

        foreach ([
            ['Life jacket', AddOnKind::Equipment, AddOnPricing::Each, 500, 'Coast Guard approved, sized on site.'],
            ['Dry bag', AddOnKind::Equipment, AddOnPricing::Each, 800, 'Keeps phones and keys dry.'],
            ['Cooler with ice', AddOnKind::Addon, AddOnPricing::Flat, 1200, 'Packed and ready at the dock.'],
        ] as [$name, $kind, $pricing, $cents, $description]) {
            if (AddOn::query()->where('name', $name)->exists()) {
                continue;
            }

            AddOn::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'description' => $description,
                'kind' => $kind,
                'pricing' => $pricing,
                'amount_cents' => $cents,
                'active' => true,
                'max_quantity' => 10,
            ]);
        }

        $kayak = Service::query()->where('name', 'Single kayak rental')->first();

        if ($kayak instanceof Service && ! ServiceDuration::query()->where('service_id', $kayak->id)->where('duration_minutes', 1440)->exists()) {
            ServiceDuration::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $kayak->id,
                'name' => '1 day',
                'quantity' => 24,
                'unit' => 'hours',
                'duration_minutes' => 1440,
            ]);
            PriceRule::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $kayak->id,
                'name' => '1 day',
                'kind' => 'duration',
                'amount_cents' => 9500,
                'window' => 'always',
                'duration_minutes' => 1440,
            ]);
        }

        foreach ([
            [CancellationPolicyKind::CustomerCancel, 1440, 100],
            [CancellationPolicyKind::CustomerCancel, 0, 50],
            [CancellationPolicyKind::NoShow, 0, 0],
        ] as [$kind, $lead, $percent]) {
            if (CancellationPolicy::query()->where('kind', $kind)->where('lead_minutes', $lead)->exists()) {
                continue;
            }

            CancellationPolicy::query()->create([
                'tenant_id' => $tenant->id,
                'kind' => $kind,
                'lead_minutes' => $lead,
                'refund_percent' => $percent,
            ]);
        }

        $this->sampleTax($tenant);

        $this->rollingCheckout($tenant);
        $this->rollingMultiDayRental($tenant);
    }

    /**
     * The approved 6% sample tax goes on every Harbor location a demo service
     * books at, not only the location the seeder first named. A booking takes
     * its location from its service, so a rate on any other location is never
     * read at checkout. Locations that already have a rate are left alone.
     */
    private function sampleTax(Tenant $tenant): void
    {
        $locationIds = Service::query()->whereNotNull('location_id')->distinct()->pluck('location_id');
        $added = 0;

        foreach (Location::query()->whereIn('id', $locationIds)->orderBy('id')->get() as $location) {
            if (! LocationTaxRule::query()->where('location_id', $location->id)->exists()) {
                LocationTaxRule::query()->create([
                    'tenant_id' => $tenant->id,
                    'location_id' => $location->id,
                    'name' => 'Sample KY sales tax',
                    'percent_bps' => 600,
                    'applies_to_rentals' => true,
                    'applies_to_add_ons' => true,
                    'applies_to_fees' => true,
                ]);
                $location->forceFill(['booking_fee_taxable' => true])->save();
                $added++;
            }
        }

        $taxed = LocationTaxRule::query()->whereIn('location_id', $locationIds)->distinct()->count('location_id');
        $untaxedServices = Service::query()->whereNull('location_id')->count();
        $this->command?->line("demo_tax=harbor-demo service_locations={$locationIds->count()} taxed_locations={$taxed} added={$added} services_without_location={$untaxedServices}");
    }

    /**
     * One demo rental that is out right now. The booking covers the same four
     * hours as the checkout. It starts an hour ago and is due back in three
     * when that return is still inside the marina's hours. When it would pass
     * closing, the return is at closing and the pickup is four hours earlier.
     * A rerun moves both forward together once the return is under an hour away
     * or when the booking and the checkout disagree.
     */
    private function rollingCheckout(Tenant $tenant): void
    {
        $service = Service::query()->with('location')->where('name', 'Tandem kayak rental')->first();
        $pool = Pool::query()->where('name', 'Tandem kayak')->first();
        $location = $service?->location;

        if (! $service instanceof Service || ! $pool instanceof Pool || ! $location instanceof Location) {
            return;
        }

        $now = CarbonImmutable::now()->utc()->startOfMinute();
        $window = $this->openCheckoutWindow($location, $now);

        if ($window === null) {
            return;
        }

        [$start, $due] = $window;
        $allocator = app(Allocator::class);
        $booking = Booking::query()->where('idempotency_key', self::DEMO_CHECKOUT_KEY)->first();

        if (! $booking instanceof Booking) {
            try {
                $booking = $allocator->hold(new AllocationIntent(
                    $tenant->id,
                    $service->id,
                    $start,
                    $due,
                    2,
                    self::DEMO_CHECKOUT_KEY,
                    'Casey Rivera',
                    null,
                ));
                $allocator->confirm($booking);
            } catch (AllocationUnavailableException) {
                return;
            }
        }

        $checkout = RentalCheckout::query()->where('booking_id', $booking->id)->whereNull('returned_at')->first();
        $bookingEnd = CarbonImmutable::instance($booking->ends_at)->utc();
        $stale = ! $checkout instanceof RentalCheckout
            || $checkout->due_at->lt($now->addHour())
            || ! $checkout->due_at->equalTo($bookingEnd);

        if (! $stale) {
            return;
        }

        if (! CarbonImmutable::instance($booking->starts_at)->utc()->equalTo($start) || ! $bookingEnd->equalTo($due)) {
            try {
                $booking = $allocator->reschedule($booking, $start, $due);
            } catch (AllocationUnavailableException) {
                return;
            }
        }

        if (! $checkout instanceof RentalCheckout) {
            RentalCheckout::query()->create([
                'tenant_id' => $tenant->id,
                'booking_id' => $booking->id,
                'pool_id' => $pool->id,
                'quantity' => 1,
                'checked_out_at' => $start,
                'due_at' => $due,
            ]);

            return;
        }

        $checkout->forceFill(['checked_out_at' => $start, 'due_at' => $due])->save();
    }

    /**
     * A four-hour span that has already started and is still due, with the
     * pickup and the return inside today's open window. A location with no
     * hours keeps the hour-ago span. A closed shop has no such span.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function openCheckoutWindow(Location $location, CarbonImmutable $now): ?array
    {
        $minutes = 240;
        $zone = $location->timezone;
        $localNow = $now->timezone($zone);
        $start = $localNow->subHour();
        $due = $start->addMinutes($minutes);
        $hours = AvailabilitySchedule::query()
            ->where('owner_type', ScheduleOwnerType::Location)
            ->where('owner_id', $location->id)
            ->where('weekday', $localNow->dayOfWeek)
            ->get();

        if ($hours->isEmpty()) {
            return [$start->utc(), $due->utc()];
        }

        $row = $hours->first(function (AvailabilitySchedule $schedule) use ($localNow): bool {
            $opens = $this->clock($schedule->opens_local);
            $closes = $this->clock($schedule->closes_local);

            return $closes > $opens
                && $localNow->format('H:i:s') >= $opens
                && $localNow->format('H:i:s') < $closes;
        });

        if (! $row instanceof AvailabilitySchedule) {
            return null;
        }

        $opens = CarbonImmutable::parse($localNow->toDateString().' '.$this->clock($row->opens_local), $zone);
        $closes = CarbonImmutable::parse($localNow->toDateString().' '.$this->clock($row->closes_local), $zone);

        if ($due->greaterThan($closes)) {
            $due = $closes;
            $start = $due->subMinutes($minutes);
        }

        if ($start->lessThan($opens)) {
            $start = $opens;
            $due = $start->addMinutes($minutes);
        }

        if ($start->greaterThanOrEqualTo($localNow) || $due->lessThanOrEqualTo($localNow) || $due->greaterThan($closes) || $start->lessThan($opens)) {
            return null;
        }

        return [$start->utc(), $due->utc()];
    }

    private function clock(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i:s');
        }

        $text = (string) $value;

        return strlen($text) === 5 ? $text.':00' : $text;
    }

    /**
     * One confirmed two-day kayak for the Harbor demo guest. The length uses
     * the one-day price already stored, and the quote does not take a payment.
     * A rerun keeps that same booking and moves it when the start is inside
     * the next seven days, or when the length is no longer two days.
     */
    private function rollingMultiDayRental(Tenant $tenant): void
    {
        if ($tenant->slug !== 'harbor-demo') {
            return;
        }

        $service = Service::query()->with('location')->where('name', 'Single kayak rental')->first();

        if (! $service instanceof Service) {
            return;
        }

        $this->ensureTwoDayLength($tenant, $service);
        $zone = $service->location?->timezone;
        $zone = is_string($zone) && $zone !== '' ? $zone : 'America/New_York';
        $start = $this->multiDayStart($zone);
        $end = $start->addMinutes(self::DEMO_MULTIDAY_MINUTES);
        $allocator = app(Allocator::class);
        $booking = Booking::query()->where('idempotency_key', self::DEMO_MULTIDAY_KEY)->first();

        try {
            if ($booking instanceof Booking && ! in_array($booking->status, [BookingStatus::Confirmed, BookingStatus::Held], true)) {
                $booking->forceFill(['idempotency_key' => null])->save();
                $booking = null;
            }

            if (! $booking instanceof Booking) {
                $booking = $allocator->hold(new AllocationIntent(
                    $tenant->id,
                    $service->id,
                    $start->utc(),
                    $end->utc(),
                    1,
                    self::DEMO_MULTIDAY_KEY,
                    self::DEMO_GUEST_NAME,
                    self::DEMO_GUEST_EMAIL,
                ));
                $booking = $allocator->confirm($booking);
            } elseif ($booking->status === BookingStatus::Held) {
                $booking = $allocator->confirm($booking);
            }

            if ($booking->status === BookingStatus::Confirmed && $this->multiDayNeedsRoll($booking)) {
                $booking = $allocator->reschedule($booking, $start->utc(), $end->utc());
            }
        } catch (AllocationUnavailableException|IdempotencyConflictException) {
            return;
        }

        if ($booking->status !== BookingStatus::Confirmed) {
            return;
        }

        if (! PricingSnapshot::query()->where('booking_id', $booking->id)->exists()) {
            try {
                app(BookingPricer::class)->quote(
                    $booking->load('location'),
                    $tenant,
                    FeeChannel::Direct,
                    0,
                    null,
                    100,
                );
            } catch (InvalidArgumentException) {
                return;
            }
        }

        $this->command?->line('demo_multiday=harbor-demo booking='.$booking->id.' starts='.$booking->starts_at->utc()->toIso8601String());
    }

    /**
     * The two-day length is required so the allocator will hold it. Its price
     * is the one-day amount already on this service, not a new rate.
     */
    private function ensureTwoDayLength(Tenant $tenant, Service $service): void
    {
        if (! ServiceDuration::query()->where('service_id', $service->id)->where('duration_minutes', self::DEMO_MULTIDAY_MINUTES)->exists()) {
            ServiceDuration::query()->create([
                'tenant_id' => $tenant->id,
                'service_id' => $service->id,
                'name' => '2 days',
                'quantity' => 48,
                'unit' => 'hours',
                'duration_minutes' => self::DEMO_MULTIDAY_MINUTES,
            ]);
        }

        if (PriceRule::query()->where('service_id', $service->id)->where('duration_minutes', self::DEMO_MULTIDAY_MINUTES)->exists()) {
            return;
        }

        $day = PriceRule::query()->where('service_id', $service->id)->where('duration_minutes', 1440)->first();

        if (! $day instanceof PriceRule) {
            return;
        }

        PriceRule::query()->create([
            'tenant_id' => $tenant->id,
            'service_id' => $service->id,
            'name' => '2 days',
            'kind' => 'duration',
            'amount_cents' => $day->amount_cents,
            'window' => 'always',
            'duration_minutes' => self::DEMO_MULTIDAY_MINUTES,
        ]);
    }

    private function multiDayStart(string $zone): CarbonImmutable
    {
        $earliest = CarbonImmutable::now($zone)->addDays(7);
        $start = $earliest->setTime(10, 0);

        if ($start->lessThan($earliest)) {
            $start = $start->addDay();
        }

        return $start;
    }

    private function multiDayNeedsRoll(Booking $booking): bool
    {
        $start = CarbonImmutable::instance($booking->starts_at)->utc();
        $end = CarbonImmutable::instance($booking->ends_at)->utc();

        return $start->lessThan(CarbonImmutable::now()->utc()->addDays(7))
            || ($end->getTimestamp() - $start->getTimestamp()) !== self::DEMO_MULTIDAY_MINUTES * 60;
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
