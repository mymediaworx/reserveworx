<?php

namespace App\Livewire\Staff\Concerns;

use App\Domain\Fees\FeeChannel;
use App\Enums\AddOnPricing;
use App\Enums\ServiceKind;
use App\Models\AddOn;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DisclosureVersion;
use App\Models\Pool;
use App\Models\PricingSnapshot;
use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\ServiceVariant;
use App\Models\ServiceVariantResource;
use App\Models\StripeAccount;
use App\Models\TenantSetting;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\Catalog\BookingAddOnLines;
use App\Services\Payments\CheckoutService;
use App\Services\Pricing\BookingPricer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * New booking from an empty schedule slot. The hold is Allocator::hold.
 * The quote is the same direct-channel BookingPricer call public checkout uses.
 *
 * @method void closeMove()
 *
 * @property string $bookResource
 * @property string $bookDate
 * @property string $bookStart
 * @property string $bookService
 * @property string $bookVariant
 * @property int $bookDuration
 * @property int $bookParty
 * @property array<string, int|string> $bookAddOns
 * @property string $bookCustomerQuery
 * @property string $bookCustomerId
 * @property string $bookCustomerName
 * @property string $bookCustomerEmail
 * @property string $bookCustomerPhone
 * @property string $bookDeparture
 * @property string $bookId
 * @property string $bookKey
 * @property ?string $bookProblem
 * @property ?string $bookPaymentUrl
 */
trait BooksFromSchedule
{
    public string $bookResource = '';

    public string $bookDate = '';

    public string $bookStart = '';

    public string $bookService = '';

    public string $bookVariant = '';

    public int $bookDuration = 60;

    public int $bookParty = 1;

    /** @var array<string, int|string> */
    public array $bookAddOns = [];

    public string $bookCustomerQuery = '';

    public string $bookCustomerId = '';

    public string $bookCustomerName = '';

    public string $bookCustomerEmail = '';

    public string $bookCustomerPhone = '';

    public string $bookDeparture = '';

    public string $bookId = '';

    public string $bookKey = '';

    public ?string $bookProblem = null;

    public ?string $bookPaymentUrl = null;

    public function openSlot(string $resource, string $date, string $start): void
    {
        $this->authorize('create', Booking::class);
        $model = $this->bookResourceModel($resource);
        abort_unless($this->validBookDate($date) && $this->validBookTime($start), 422);
        $this->closeMove();
        $this->closeBooking();
        $this->bookResource = $resource;
        $this->bookDate = $date;
        $this->bookStart = $start;
        $this->bookKey = (string) Str::uuid();
        $first = $this->servicesFor($model)->first();

        if ($first instanceof Service) {
            $this->chooseService($first->id);
        }
    }

    public function closeBooking(): void
    {
        $this->reset(
            'bookResource',
            'bookDate',
            'bookStart',
            'bookService',
            'bookVariant',
            'bookDuration',
            'bookParty',
            'bookAddOns',
            'bookCustomerQuery',
            'bookCustomerId',
            'bookCustomerName',
            'bookCustomerEmail',
            'bookCustomerPhone',
            'bookDeparture',
            'bookId',
            'bookKey',
            'bookProblem',
            'bookPaymentUrl',
        );
    }

    public function chooseService(string $serviceId): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $resource = $this->bookResourceModel($this->bookResource);
        $service = $this->servicesFor($resource)->firstWhere('id', $serviceId);
        abort_unless($service instanceof Service, 404);
        $this->bookService = $service->id;
        $this->bookVariant = '';
        $this->bookDeparture = '';
        $this->bookProblem = null;
        $this->bookParty = max(1, (int) ($service->min_participants ?? 1));
        $variants = $this->fittingVariants($service, $resource);
        $picked = $variants->first();

        if (! $this->serviceUsesResource($service, $resource) && $picked instanceof ServiceVariant) {
            $this->applyVariant($picked);
        } else {
            $this->bookDuration = $this->defaultDuration($service);
        }

        $this->preselectDeparture($service, $resource);
    }

    public function chooseVariant(string $variantId): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $service = $this->selectedService();
        abort_unless($service instanceof Service, 404);
        $this->bookProblem = null;

        if ($variantId === '') {
            abort_unless($this->serviceUsesResource($service, $this->bookResourceModel($this->bookResource)), 404);
            $this->bookVariant = '';
            $this->bookDuration = $this->defaultDuration($service);

            return;
        }

        $variant = $this->fittingVariants($service, $this->bookResourceModel($this->bookResource))->firstWhere('id', $variantId);
        abort_unless($variant instanceof ServiceVariant, 404);
        $this->applyVariant($variant);
    }

    public function chooseDuration(int $minutes): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $service = $this->selectedService();
        abort_unless($service instanceof Service, 404);
        abort_unless(in_array($minutes, $this->durationChoices($service), true), 422);
        $this->bookDuration = $minutes;
        $this->bookProblem = null;
    }

    public function chooseStart(string $start): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        abort_unless($this->validBookTime($start), 422);
        $this->bookStart = $start;
        $this->bookProblem = null;
        $service = $this->selectedService();

        if ($service instanceof Service) {
            $this->preselectDeparture($service, $this->bookResourceModel($this->bookResource));
        }
    }

    public function chooseDeparture(string $departureId): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $service = $this->selectedService();
        abort_unless($service instanceof Service && $service->kind === ServiceKind::Tour, 404);
        $departure = Departure::query()->where('service_id', $service->id)->find($departureId);
        abort_unless($departure instanceof Departure, 404);
        $this->bookDeparture = $departure->id;
        $this->bookDuration = $this->departureMinutes($departure);
        $this->bookProblem = null;
    }

    public function stepParty(int $direction): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $service = $this->selectedService();
        abort_unless($service instanceof Service, 404);
        $min = max(1, (int) ($service->min_participants ?? 1));
        $max = $service->max_participants ?? 500;
        $next = (int) $this->bookParty + ($direction < 0 ? -1 : 1);
        $this->bookParty = max($min, min((int) $max, $next));
        $this->bookProblem = null;
    }

    public function toggleAddOn(string $addOnId): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $addOn = AddOn::query()->where('active', true)->find($addOnId);
        abort_unless($addOn instanceof AddOn, 404);
        abort_unless($addOn->pricing !== AddOnPricing::Each, 422);
        $chosen = $this->bookAddOns;

        if ((int) ($chosen[$addOnId] ?? 0) > 0) {
            unset($chosen[$addOnId]);
        } else {
            $chosen[$addOnId] = 1;
        }

        $this->bookAddOns = $chosen;
        $this->bookProblem = null;
    }

    public function selectCustomer(string $customerId): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $customer = Customer::query()->whereNull('anonymized_at')->find($customerId);
        abort_unless($customer instanceof Customer, 404);
        $this->bookCustomerId = $customer->id;
        $this->bookCustomerName = $customer->name;
        $this->bookCustomerEmail = (string) ($customer->email ?? '');
        $this->bookCustomerPhone = (string) ($customer->phone ?? '');
        $this->bookProblem = null;
    }

    public function clearCustomer(): void
    {
        $this->authorize('create', Booking::class);

        if ($this->bookId !== '') {
            return;
        }

        $this->bookCustomerId = '';
        $this->bookCustomerName = '';
        $this->bookCustomerEmail = '';
        $this->bookCustomerPhone = '';
        $this->bookCustomerQuery = '';
    }

    public function holdBooking(Allocator $allocator, BookingPricer $pricer): void
    {
        $booking = $this->place($allocator, $pricer);

        if (! $booking instanceof Booking || $this->bookProblem !== null) {
            return;
        }

        $this->redirect('/staff/bookings/'.$booking->id, navigate: true);
    }

    public function payAtPickup(Allocator $allocator, BookingPricer $pricer, CheckoutService $checkout): void
    {
        $booking = $this->place($allocator, $pricer);

        if (! $booking instanceof Booking || ! $this->hasQuote($booking)) {
            return;
        }

        try {
            $payment = $checkout->recordOffline($booking);
        } catch (AllocationUnavailableException $exception) {
            $this->bookProblem = $exception->getMessage();

            return;
        }

        $this->bookProblem = null;
        $this->audit('payment.offline', $payment, ['booking_id' => $booking->id]);
        $this->redirect('/staff/bookings/'.$booking->id, navigate: true);
    }

    public function sendPaymentLink(Allocator $allocator, BookingPricer $pricer, CheckoutService $checkout): void
    {
        $booking = $this->place($allocator, $pricer);

        if (! $booking instanceof Booking || ! $this->hasQuote($booking)) {
            return;
        }

        $back = url('/staff/bookings/'.$booking->id);

        try {
            $started = $checkout->startCheckout($booking->load('service'), $back.'?paid=1', $back);
        } catch (AllocationUnavailableException $exception) {
            $this->bookProblem = $exception->getMessage();

            return;
        }

        $this->bookPaymentUrl = $started['url'];
        $this->bookProblem = null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bookingPanel(): ?array
    {
        if ($this->bookResource === '') {
            return null;
        }

        $resource = $this->bookResourceModel($this->bookResource);
        $zone = $this->bookZone($resource);
        $services = $this->servicesFor($resource);
        $service = $services->firstWhere('id', $this->bookService);
        $service = $service instanceof Service ? $service : null;
        $variants = $service instanceof Service ? $this->fittingVariants($service, $resource) : collect();
        $allowsBase = $service instanceof Service && $this->serviceUsesResource($service, $resource);
        $addOns = AddOn::query()->where('active', true)->orderBy('name')->orderBy('id')->get();
        $customers = $this->customerMatches();
        $quote = $this->previewQuote($service, $resource);
        $snapshot = $this->bookId !== ''
            ? PricingSnapshot::query()->where('booking_id', $this->bookId)->first()
            : null;
        $payload = $snapshot instanceof PricingSnapshot ? $snapshot->payload : ($quote['payload'] ?? null);
        $disclosure = null;

        if ($snapshot instanceof PricingSnapshot && $snapshot->disclosure_version_id !== null) {
            $disclosure = DisclosureVersion::query()->find($snapshot->disclosure_version_id);
        } elseif ($quote !== null) {
            $disclosure = $quote['disclosure'];
        }

        $currency = $snapshot instanceof PricingSnapshot
            ? $snapshot->currency
            : ($quote['currency'] ?? 'USD');
        $setting = $this->settings();

        return [
            'resourceName' => $resource->name,
            'zone' => $zone,
            'when' => $this->slotLabel($zone),
            'services' => $services,
            'service' => $service,
            'variants' => $variants,
            'allowsBase' => $allowsBase,
            'durations' => $service instanceof Service ? $this->durationChoices($service) : [],
            'starts' => $this->startChoices($zone),
            'departures' => $service instanceof Service ? $this->departuresFor($service, $zone) : collect(),
            'addOns' => $addOns,
            'customers' => $customers,
            'payload' => is_array($payload) ? $payload : null,
            'disclosure' => $disclosure instanceof DisclosureVersion ? $disclosure : null,
            'currency' => $currency !== '' ? $currency : 'USD',
            'quoteError' => $quote['error'] ?? null,
            'pickupCents' => is_array($payload) && is_numeric($payload['customer_total_cents'] ?? null)
                ? $this->pickupCents($payload)
                : null,
            'canCharge' => StripeAccount::query()->first()?->canCharge() ?? false,
            'payAtPickup' => $setting instanceof TenantSetting && $setting->pay_at_pickup,
            'placed' => $this->bookId !== '',
        ];
    }

    private function place(Allocator $allocator, BookingPricer $pricer): ?Booking
    {
        $this->authorize('create', Booking::class);
        $this->bookProblem = null;

        if ($this->bookId !== '') {
            $existing = Booking::query()->find($this->bookId);
            abort_unless($existing instanceof Booking, 404);
            $this->authorize('update', $existing);
            $this->ensureQuoted($pricer, $existing);

            return $existing;
        }

        $resource = $this->bookResourceModel($this->bookResource);
        $service = $this->selectedService();
        abort_unless($service instanceof Service, 404);
        $this->validate([
            'bookParty' => ['required', 'integer', 'min:1', 'max:500'],
            'bookCustomerName' => ['required', 'string', 'max:120'],
            'bookCustomerEmail' => ['nullable', 'email', 'max:255'],
            'bookCustomerPhone' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->bookCustomerId !== '') {
            abort_unless(
                Customer::query()->whereNull('anonymized_at')->whereKey($this->bookCustomerId)->exists(),
                404,
            );
        }

        $interval = $this->bookInterval($service, $resource);

        if ($interval === null) {
            $this->bookProblem = $service->kind === ServiceKind::Tour ? 'Choose a departure.' : 'Choose a start time.';

            return null;
        }

        $lines = $this->addOnLines();

        if ($lines === null) {
            return null;
        }

        [$start, $end, $departureId] = $interval;
        $intent = new AllocationIntent(
            $this->tenant()->id,
            $service->id,
            $start,
            $end,
            (int) $this->bookParty,
            $this->bookKey !== '' ? $this->bookKey : null,
            $this->bookCustomerName,
            $this->bookCustomerEmail !== '' ? $this->bookCustomerEmail : null,
            $departureId,
            null,
            $this->bookCustomerPhone !== '' ? $this->bookCustomerPhone : null,
            $lines,
            $this->bookVariant !== '' ? $this->bookVariant : null,
            $this->bookCustomerId !== '' ? $this->bookCustomerId : null,
        );

        try {
            $booking = $allocator->hold($intent);
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            $this->bookProblem = $exception->getMessage();
            $this->bookKey = (string) Str::uuid();

            return null;
        }

        $this->bookId = $booking->id;

        if ($booking->created_by === null) {
            $booking->created_by = $this->currentUser()->id;
            $booking->save();
        }

        $this->audit('booking.held', $booking, [
            'service_id' => $booking->service_id,
            'starts_at' => $booking->starts_at->toIso8601String(),
            'channel' => 'staff_schedule',
        ]);
        $this->ensureQuoted($pricer, $booking);

        return $booking;
    }

    private function ensureQuoted(BookingPricer $pricer, Booking $booking): void
    {
        try {
            $pricer->quote($booking->load('location'), $this->tenant(), FeeChannel::Direct, 0, null, 100);
        } catch (InvalidArgumentException $exception) {
            $this->bookProblem = $exception->getMessage();
        }
    }

    private function hasQuote(Booking $booking): bool
    {
        if ($this->bookProblem !== null && ! PricingSnapshot::query()->where('booking_id', $booking->id)->exists()) {
            return false;
        }

        return PricingSnapshot::query()->where('booking_id', $booking->id)->exists();
    }

    /**
     * @return array{payload: array<string, mixed>, disclosure: ?DisclosureVersion, currency: string, error: ?string}|null
     */
    private function previewQuote(?Service $service, Asset|Pool $resource): ?array
    {
        if (! $service instanceof Service || $this->bookId !== '') {
            return null;
        }

        $interval = $this->bookInterval($service, $resource);

        if ($interval === null) {
            return [
                'payload' => [],
                'disclosure' => null,
                'currency' => 'USD',
                'error' => $service->kind === ServiceKind::Tour ? 'Choose a departure.' : null,
            ];
        }

        try {
            $lines = app(BookingAddOnLines::class)->price(max(1, (int) $this->bookParty), $this->requestedAddOns());
        } catch (ValidationException $exception) {
            return [
                'payload' => [],
                'disclosure' => null,
                'currency' => 'USD',
                'error' => (string) $exception->validator->errors()->first(),
            ];
        }

        $variant = $this->bookVariant !== ''
            ? $this->fittingVariants($service, $resource)->firstWhere('id', $this->bookVariant)
            : null;

        try {
            $selection = app(BookingPricer::class)->priceSelection(
                $this->tenant(),
                $service->id,
                $service->location_id,
                $this->bookZone($resource),
                $variant instanceof ServiceVariant ? $variant : null,
                $interval[0],
                $interval[1],
                max(1, (int) $this->bookParty),
                $lines,
                FeeChannel::Direct,
                0,
                null,
                100,
            );
        } catch (InvalidArgumentException $exception) {
            return [
                'payload' => [],
                'disclosure' => null,
                'currency' => 'USD',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'payload' => $selection['payload'],
            'disclosure' => $selection['disclosure'],
            'currency' => $selection['currency'],
            'error' => null,
        ];
    }

    /**
     * @return list<array{add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>|null
     */
    private function addOnLines(): ?array
    {
        try {
            return app(BookingAddOnLines::class)->price(max(1, (int) $this->bookParty), $this->requestedAddOns());
        } catch (ValidationException $exception) {
            $this->bookProblem = (string) $exception->validator->errors()->first();

            return null;
        }
    }

    /**
     * @return list<array{id: string, quantity: int}>
     */
    private function requestedAddOns(): array
    {
        $requested = [];

        foreach ($this->bookAddOns as $id => $quantity) {
            if ($id === '' || ! is_numeric($quantity) || (int) $quantity < 1) {
                continue;
            }

            $requested[] = ['id' => $id, 'quantity' => (int) $quantity];
        }

        return $requested;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string|null}|null
     */
    private function bookInterval(Service $service, Asset|Pool $resource): ?array
    {
        if ($service->kind === ServiceKind::Tour) {
            $departure = $this->bookDeparture !== ''
                ? Departure::query()->where('service_id', $service->id)->find($this->bookDeparture)
                : null;

            if (! $departure instanceof Departure) {
                return null;
            }

            return [
                CarbonImmutable::instance($departure->starts_at)->utc(),
                CarbonImmutable::instance($departure->ends_at)->utc(),
                $departure->id,
            ];
        }

        if (! $this->validBookTime($this->bookStart) || $this->bookDuration < 1) {
            return null;
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->bookDate.' '.$this->bookStart, $this->bookZone($resource));

        if (! $start instanceof CarbonImmutable) {
            return null;
        }

        return [$start->utc(), $start->addMinutes($this->bookDuration)->utc(), null];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pickupCents(array $payload): int
    {
        $total = $payload['customer_total_cents'] ?? 0;
        $line = $payload['customer_fee_line_cents'] ?? 0;

        return max(0, (is_numeric($total) ? (int) $total : 0) - (is_numeric($line) ? (int) $line : 0));
    }

    private function bookResourceModel(string $key): Asset|Pool
    {
        if (str_starts_with($key, 'asset:')) {
            $asset = Asset::query()->find(substr($key, 6));
            abort_unless($asset instanceof Asset, 404);

            return $asset;
        }

        if (str_starts_with($key, 'pool:')) {
            $pool = Pool::query()->find(substr($key, 5));
            abort_unless($pool instanceof Pool, 404);

            return $pool;
        }

        abort(404);
    }

    private function bookZone(Asset|Pool $resource): string
    {
        $resource->loadMissing('location');
        $zone = $resource->location?->timezone;

        return is_string($zone) && $zone !== '' ? $zone : 'UTC';
    }

    /**
     * @return Collection<int, Service>
     */
    private function servicesFor(Asset|Pool $resource): Collection
    {
        $type = $resource instanceof Asset ? 'asset' : 'pool';
        $direct = ResourceRequirement::query()
            ->where('resource_type', $type)
            ->where('resource_id', $resource->id)
            ->pluck('service_id');
        $variantIds = ServiceVariantResource::query()
            ->where('resource_type', $type)
            ->where('resource_id', $resource->id)
            ->pluck('service_variant_id');
        $viaVariant = ServiceVariant::query()->whereIn('id', $variantIds)->pluck('service_id');

        return Service::query()
            ->with(['durations', 'variants.resources', 'requirements', 'location'])
            ->where('location_id', $resource->location_id)
            ->whereIn('id', $direct->merge($viaVariant)->unique()->filter()->values())
            ->orderBy('name')
            ->get();
    }

    private function selectedService(): ?Service
    {
        if ($this->bookResource === '' || $this->bookService === '') {
            return null;
        }

        $resource = $this->bookResourceModel($this->bookResource);

        $service = $this->servicesFor($resource)->firstWhere('id', $this->bookService);

        return $service instanceof Service ? $service : null;
    }

    private function serviceUsesResource(Service $service, Asset|Pool $resource): bool
    {
        $type = $resource instanceof Asset ? 'asset' : 'pool';

        return $service->requirements->contains(
            fn (ResourceRequirement $requirement): bool => $requirement->resource_type->value === $type
                && $requirement->resource_id === $resource->id,
        );
    }

    /**
     * @return Collection<int, ServiceVariant>
     */
    private function fittingVariants(Service $service, Asset|Pool $resource): Collection
    {
        $type = $resource instanceof Asset ? 'asset' : 'pool';
        $allowsBase = $this->serviceUsesResource($service, $resource);

        return $service->variants
            ->filter(function (ServiceVariant $variant) use ($type, $resource, $allowsBase): bool {
                $rows = $variant->resources;

                if ($rows->isEmpty()) {
                    return $allowsBase;
                }

                return $rows->contains(
                    fn (ServiceVariantResource $row): bool => $row->resource_type->value === $type
                        && $row->resource_id === $resource->id,
                );
            })
            ->values();
    }

    private function applyVariant(ServiceVariant $variant): void
    {
        $this->bookVariant = $variant->id;
        $this->bookDuration = $variant->duration_minutes;
    }

    private function defaultDuration(Service $service): int
    {
        $duration = $service->durations->sortBy('duration_minutes')->first();

        return $duration instanceof ServiceDuration ? $duration->duration_minutes : 60;
    }

    /**
     * @return list<int>
     */
    private function durationChoices(Service $service): array
    {
        if ($this->bookVariant !== '') {
            $variant = $service->variants->firstWhere('id', $this->bookVariant);

            return $variant instanceof ServiceVariant ? [$variant->duration_minutes] : [];
        }

        if ($service->durations->isEmpty()) {
            return [60, 120, 240];
        }

        return $service->durations
            ->sortBy('duration_minutes')
            ->map(fn (ServiceDuration $duration): int => $duration->duration_minutes)
            ->values()
            ->all();
    }

    /**
     * @return list<array{start: string, label: string}>
     */
    private function startChoices(string $zone): array
    {
        if (! $this->validBookDate($this->bookDate)) {
            return [];
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $this->bookDate, $zone);

        if (! $day instanceof CarbonImmutable) {
            return [];
        }

        $day = $day->startOfDay();
        $end = $day->addDay()->startOfDay();
        $choices = [];

        for ($cursor = $day, $guard = 0; $guard < 60 && $cursor < $end; $guard++, $cursor = $cursor->addMinutes(30)) {
            $choices[] = [
                'start' => $cursor->format('H:i'),
                'label' => $cursor->format('g:i A'),
            ];
        }

        return $choices;
    }

    /**
     * @return Collection<int, Departure>
     */
    private function departuresFor(Service $service, string $zone): Collection
    {
        if ($service->kind !== ServiceKind::Tour || ! $this->validBookDate($this->bookDate)) {
            return collect();
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $this->bookDate, $zone);

        if (! $day instanceof CarbonImmutable) {
            return collect();
        }

        $from = $day->startOfDay()->utc();

        return Departure::query()
            ->where('service_id', $service->id)
            ->where('status', 'scheduled')
            ->where('starts_at', '>=', $from)
            ->where('starts_at', '<', $from->addDay())
            ->orderBy('starts_at')
            ->get();
    }

    private function preselectDeparture(Service $service, Asset|Pool $resource): void
    {
        if ($service->kind !== ServiceKind::Tour) {
            $this->bookDeparture = '';

            return;
        }

        $zone = $this->bookZone($resource);
        $match = $this->departuresFor($service, $zone)->first(
            fn (Departure $departure): bool => CarbonImmutable::instance($departure->starts_at)->timezone($zone)->format('H:i') === $this->bookStart,
        );

        if ($match instanceof Departure) {
            $this->bookDeparture = $match->id;
            $this->bookDuration = $this->departureMinutes($match);
        }
    }

    private function departureMinutes(Departure $departure): int
    {
        $seconds = CarbonImmutable::instance($departure->ends_at)->utc()->getTimestamp()
            - CarbonImmutable::instance($departure->starts_at)->utc()->getTimestamp();

        return max(1, intdiv($seconds, 60));
    }

    /**
     * @return Collection<int, Customer>
     */
    private function customerMatches(): Collection
    {
        $term = trim($this->bookCustomerQuery);

        if ($this->bookCustomerId !== '' || mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return Customer::query()
            ->whereNull('anonymized_at')
            ->where(function ($query) use ($like): void {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('phone', 'like', $like);
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(8)
            ->get();
    }

    private function slotLabel(string $zone): string
    {
        if (! $this->validBookDate($this->bookDate) || ! $this->validBookTime($this->bookStart)) {
            return '';
        }

        $start = CarbonImmutable::createFromFormat('Y-m-d H:i', $this->bookDate.' '.$this->bookStart, $zone);

        return $start instanceof CarbonImmutable ? $start->format('D, M j, g:i A') : '';
    }

    private function validBookDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) !== 1) {
            return false;
        }

        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private function validBookTime(string $value): bool
    {
        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
