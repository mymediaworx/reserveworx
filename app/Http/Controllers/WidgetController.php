<?php

namespace App\Http\Controllers;

use App\Domain\Fees\Disclosures\Money;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Pricing\PricingEngine;
use App\Enums\BookingStatus;
use App\Enums\FeePayer;
use App\Enums\PaymentStatus;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\DisclosureVersion;
use App\Models\Hold;
use App\Models\Location;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\WidgetToken;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Services\Booking\Waitlist;
use App\Services\Catalog\BookingOperatorFeeLines;
use App\Services\CheckIn\CheckInPass;
use App\Services\Inventory\OpeningHours;
use App\Services\Notifications\WaitlistJoinedMail;
use App\Services\Payments\CheckoutService;
use App\Services\Pricing\BookingPricer;
use App\Services\Widget\WidgetTokens;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;

class WidgetController extends Controller
{
    public function __construct(
        private readonly WidgetTokens $tokens,
        private readonly Allocator $allocator,
        private readonly PricingEngine $pricing,
        private readonly BookingPricer $pricer,
        private readonly CheckoutService $checkout,
        private readonly BookingOperatorFeeLines $operatorFees,
        private readonly CheckInPass $checkInPass,
    ) {}

    public function services(Request $request, string $token): View
    {
        $tenant = $this->tenant($request);
        $this->activeToken($token);
        $setting = $this->setting($tenant);

        $services = Service::query()->with(['location', 'durations'])->orderBy('name')->get()->map(function (Service $service) use ($setting): array {
            $preview = $this->preview($service, $setting);

            return [
                'service' => $service,
                'total_cents' => $preview['total_cents'],
                'fee_included' => $preview['fee_included'],
                'currency' => $preview['currency'],
            ];
        });

        return view('widget.services', [
            'token' => $token,
            'tenantName' => $tenant->name,
            'services' => $services,
        ]);
    }

    public function create(Request $request, string $token, Service $service): View
    {
        $this->activeToken($token);
        $service->load(['location', 'durations']);

        return view('widget.form', [
            'token' => $token,
            'service' => $service,
            'error' => null,
            'old' => [],
            'suggestions' => [],
            'offerWaitlist' => false,
        ]);
    }

    public function store(Request $request, string $token, Service $service): Response|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->activeToken($token);
        $service->load(['location', 'durations']);
        $validated = $this->validateBooking($request, $service);

        if (is_string($validated)) {
            return $this->formResponse($token, $service, $validated, $request, 422);
        }

        $zone = $service->location instanceof Location ? $service->location->timezone : 'UTC';
        $starts = CarbonImmutable::createFromFormat('Y-m-d H:i', $validated['date'].' '.$validated['start'], $zone);

        if (! $starts instanceof CarbonImmutable) {
            return $this->formResponse($token, $service, 'Choose a valid date and time.', $request, 422);
        }

        $startsAt = $starts->utc();
        $endsAt = $startsAt->addMinutes($validated['minutes']);

        try {
            $this->previewAt($service, $this->setting($tenant), $validated['participants'], $validated['minutes'], $starts);
            $booking = $this->allocator->hold(new AllocationIntent(
                $tenant->id,
                $service->id,
                $startsAt,
                $endsAt,
                $validated['participants'],
                $validated['idempotency_key'],
                $validated['customer_name'],
                $validated['customer_email'],
            ));
            $this->pricer->quote($booking->load('location'), $tenant, FeeChannel::Direct, 0, null, 100);
        } catch (InvalidArgumentException|IdempotencyConflictException|AllocationUnavailableException $exception) {
            $offers = $exception instanceof AllocationUnavailableException ? $exception->offers : [];
            $offerWaitlist = $exception instanceof AllocationUnavailableException
                && $service->waitlist_enabled
                && $this->slotIsFull($exception->getMessage());

            return $this->formResponse($token, $service, $exception->getMessage(), $request, 409, $offers, $offerWaitlist);
        }

        $expires = $this->signatureExpiry($booking);

        return redirect()->to(URL::temporarySignedRoute('widget.checkout', $expires, [
            'token' => $token,
            'booking' => $booking->id,
        ]));
    }

    public function joinWaitlist(
        Request $request,
        string $token,
        Service $service,
        Waitlist $waitlist,
        AuditLogger $audit,
        WaitlistJoinedMail $mail,
        OpeningHours $hours,
        Allocator $allocator,
    ): Response {
        $tenant = $this->tenant($request);
        $this->activeToken($token);
        $service->load(['location', 'durations']);
        abort_unless($service->waitlist_enabled, 404);

        $validated = $this->validateBooking($request, $service);

        if (is_string($validated)) {
            return $this->formResponse($token, $service, $validated, $request, 422, [], true);
        }

        $phoneError = Validator::make($request->all(), [
            'customer_phone' => ['nullable', 'string', 'max:255'],
        ])->errors()->first();

        if ($phoneError !== '') {
            return $this->formResponse($token, $service, $phoneError, $request, 422, [], true);
        }

        $zone = $service->location instanceof Location ? $service->location->timezone : 'UTC';
        $starts = CarbonImmutable::createFromFormat('Y-m-d H:i', $validated['date'].' '.$validated['start'], $zone);

        if (! $starts instanceof CarbonImmutable) {
            return $this->formResponse($token, $service, 'Choose a valid date and time.', $request, 422, [], true);
        }

        $startsAt = $starts->utc();
        $endsAt = $startsAt->addMinutes($validated['minutes']);
        $closed = $this->closedMessage($hours, $service->location, $startsAt, $endsAt);

        if ($closed !== null) {
            return $this->formResponse($token, $service, $closed, $request, 409);
        }

        $hint = $allocator->hint(new AllocationIntent(
            $tenant->id,
            $service->id,
            $startsAt,
            $endsAt,
            $validated['participants'],
            null,
            $validated['customer_name'],
            $validated['customer_email'],
        ));

        if ($hint['available']) {
            return $this->formResponse($token, $service, 'That time is open. Review the price and hold it.', $request, 409);
        }

        $phone = $request->input('customer_phone');
        $entry = $waitlist->join(
            $tenant->id,
            $service->id,
            null,
            $service->location_id,
            $startsAt,
            $endsAt,
            $validated['participants'],
            $validated['customer_name'],
            $validated['customer_email'],
            null,
            $validated['idempotency_key'],
            null,
            is_string($phone) ? $phone : null,
        );

        $created = $entry->wasRecentlyCreated;
        $emailed = false;

        if ($created) {
            $audit->log('waitlist.joined', $entry, ['service_id' => $service->id], request: $request);
            $emailed = $mail->send($entry);
        }

        return response()->view('widget.waitlist-joined', [
            'token' => $token,
            'serviceName' => $service->name,
            'when' => $starts->format('D, M j, g:i A'),
            'participants' => $entry->participants,
            'email' => (string) ($entry->customer_email ?? $validated['customer_email']),
            'emailed' => $emailed,
            'already' => ! $created,
        ]);
    }

    public function checkout(Request $request, string $token, Booking $booking): View
    {
        $this->activeToken($token);
        $this->assertBookingService($booking);
        $snapshot = $this->snapshot($booking);
        $hold = $this->openHold($booking);

        $pickup = $this->pickupOffer($this->tenant($request), $token, $booking, $snapshot, $hold);

        return view('widget.checkout', [
            'token' => $token,
            'booking' => $booking->load('service'),
            'snapshot' => $snapshot,
            'terms' => $this->terms($snapshot),
            'hold' => $hold,
            'payUrl' => $hold instanceof Hold ? URL::temporarySignedRoute('widget.pay', $hold->expires_at, [
                'token' => $token,
                'booking' => $booking->id,
            ]) : null,
            'pickupUrl' => $pickup['url'],
            'pickupLabel' => $pickup['label'],
            'promoUrl' => $this->promoUrl($token, $booking, $hold),
            'promoValue' => $this->promoValue($snapshot),
            'error' => null,
        ]);
    }

    public function applyPromo(Request $request, string $token, Booking $booking): Response|RedirectResponse
    {
        $this->activeToken($token);
        $this->assertBookingService($booking);
        $fresh = $booking->fresh() ?? $booking;
        $snapshot = $this->snapshot($fresh);
        $hold = $this->openHold($fresh);
        $submitted = trim((string) $request->input('promo_code', ''));

        if (! $hold instanceof Hold) {
            return $this->checkoutResponse($token, $fresh, $snapshot, null, null, null, null, 'This hold has expired.', 409, $submitted);
        }

        $expires = CarbonImmutable::instance($hold->expires_at);
        $payUrl = URL::temporarySignedRoute('widget.pay', $expires, [
            'token' => $token,
            'booking' => $fresh->id,
        ]);
        $pickup = $this->pickupOffer($this->tenant($request), $token, $fresh, $snapshot, $hold);

        if ($submitted === '') {
            return $this->checkoutResponse(
                $token,
                $fresh,
                $snapshot,
                $hold,
                $payUrl,
                $pickup['url'],
                $pickup['label'],
                'Enter a promo code.',
                422,
                $submitted,
            );
        }

        try {
            $this->pricer->applyPromo($fresh->load('location'), $this->tenant($request), $submitted);
        } catch (InvalidArgumentException $exception) {
            return $this->checkoutResponse(
                $token,
                $fresh,
                $snapshot,
                $hold,
                $payUrl,
                $pickup['url'],
                $pickup['label'],
                $exception->getMessage(),
                422,
                $submitted,
            );
        }

        return redirect()->to(URL::temporarySignedRoute('widget.checkout', $expires, [
            'token' => $token,
            'booking' => $fresh->id,
        ]));
    }

    public function pickup(Request $request, string $token, Booking $booking): Response|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->activeToken($token);
        $this->assertBookingService($booking);
        $setting = $this->setting($tenant);
        abort_unless($setting instanceof TenantSetting && $setting->pay_at_pickup, 404);

        $fresh = $booking->fresh() ?? $booking;
        $hold = $this->openHold($fresh);

        if ($fresh->status === BookingStatus::Confirmed) {
            return redirect()->to($this->confirmationUrl($token, $fresh, $hold));
        }

        $snapshot = $this->snapshot($fresh);

        if (! $hold instanceof Hold) {
            return $this->checkoutResponse($token, $fresh, $snapshot, null, null, null, null, 'This hold has expired.', 409);
        }

        try {
            $fresh = $this->allocator->confirm($fresh);
            $fresh = $fresh->fresh() ?? $fresh;

            if ($fresh->status === BookingStatus::Confirmed) {
                $fresh->payment_status = PaymentStatus::DueAtPickup;
                $fresh->save();
            }
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            return $this->checkoutResponse(
                $token,
                $fresh,
                $snapshot,
                $hold,
                URL::temporarySignedRoute('widget.pay', CarbonImmutable::instance($hold->expires_at), [
                    'token' => $token,
                    'booking' => $fresh->id,
                ]),
                URL::temporarySignedRoute('widget.pickup', CarbonImmutable::instance($hold->expires_at), [
                    'token' => $token,
                    'booking' => $fresh->id,
                ]),
                Money::format($this->checkout->amountDueAtPickup($snapshot), $snapshot->currency),
                $exception->getMessage(),
                409,
            );
        }

        return redirect()->to($this->confirmationUrl($token, $fresh, $hold));
    }

    public function pay(Request $request, string $token, Booking $booking): Response|RedirectResponse
    {
        $this->activeToken($token);
        $this->assertBookingService($booking);
        $hold = $this->openHold($booking);
        $snapshot = $this->snapshot($booking);

        if (! $hold instanceof Hold) {
            return $this->checkoutResponse($token, $booking, $snapshot, null, null, null, null, 'This hold has expired.', 409);
        }

        $expires = CarbonImmutable::instance($hold->expires_at);
        $success = URL::temporarySignedRoute('widget.confirmation', $expires, [
            'token' => $token,
            'booking' => $booking->id,
        ]);
        $cancel = URL::temporarySignedRoute('widget.checkout', $expires, [
            'token' => $token,
            'booking' => $booking->id,
        ]);

        try {
            $started = $this->checkout->startCheckout($booking->load('service'), $success, $cancel);
        } catch (AllocationUnavailableException $exception) {
            $pickup = $this->pickupOffer($this->tenant($request), $token, $booking, $snapshot, $hold);

            return $this->checkoutResponse(
                $token,
                $booking,
                $snapshot,
                $hold,
                URL::temporarySignedRoute('widget.pay', $expires, [
                    'token' => $token,
                    'booking' => $booking->id,
                ]),
                $pickup['url'],
                $pickup['label'],
                $exception->getMessage(),
                409,
            );
        }

        return redirect()->away((string) $started['url']);
    }

    public function confirmation(Request $request, string $token, Booking $booking): View
    {
        $this->activeToken($token);
        $fresh = $booking->fresh() ?? $booking;

        $fresh->load('service');

        return view('widget.confirmation', [
            'booking' => $fresh,
            'checkInQr' => $this->checkInPass->svg($fresh),
            'checkInUrl' => $this->checkInPass->url($fresh),
        ]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    private function activeToken(string $plain): WidgetToken
    {
        $token = $this->tokens->findActive($plain);
        abort_unless($token instanceof WidgetToken, 404);

        return $token;
    }

    private function setting(Tenant $tenant): ?TenantSetting
    {
        return TenantSetting::query()->where('tenant_id', $tenant->id)->first();
    }

    /**
     * @return array{total_cents: int|null, fee_included: bool, currency: string}
     */
    private function preview(Service $service, ?TenantSetting $setting): array
    {
        $firstDuration = $service->durations->first();
        $minutes = $firstDuration instanceof ServiceDuration ? $firstDuration->duration_minutes : 60;
        $zone = $service->location instanceof Location ? $service->location->timezone : 'UTC';

        try {
            return $this->previewAt($service, $setting, max(1, (int) ($service->min_participants ?? 1)), $minutes, CarbonImmutable::now($zone));
        } catch (InvalidArgumentException) {
            return [
                'total_cents' => null,
                'fee_included' => $setting instanceof TenantSetting && $setting->fee_payer === FeePayer::CustomerPaid,
                'currency' => $setting instanceof TenantSetting ? $setting->currency : 'USD',
            ];
        }
    }

    /**
     * @return array{total_cents: int, fee_included: bool, currency: string}
     */
    private function previewAt(Service $service, ?TenantSetting $setting, int $participants, int $minutes, CarbonImmutable $on): array
    {
        $channel = FeeChannel::Direct;
        $rate = $setting instanceof TenantSetting
            ? FeeRate::fromTenantSetting($setting, $channel)
            : FeeRate::defaultFor($channel);
        $payer = $setting instanceof TenantSetting ? $setting->fee_payer : FeePayer::CustomerPaid;
        $rules = PriceRule::inputsFor($service->id);
        $priced = $this->pricing->price(
            $channel,
            $rate,
            $payer,
            $rules,
            $participants,
            $minutes,
            $on,
            0,
            null,
            null,
            null,
            100,
            0,
            [],
            $this->operatorFees->definitionsFor($service->id, $service->location_id),
        );

        return [
            'total_cents' => $priced->quote->customerTotalCents(),
            'fee_included' => $priced->quote->customerFeeLineCents() !== null,
            'currency' => $setting instanceof TenantSetting ? $setting->currency : 'USD',
        ];
    }

    /**
     * @return array{date: string, start: string, participants: int, customer_name: string, customer_email: string, idempotency_key: string, minutes: int}|string
     */
    private function validateBooking(Request $request, Service $service): array|string
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'participants' => ['required', 'integer', 'min:1'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'duration_id' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);

        if ($validator->fails()) {
            return (string) $validator->errors()->first();
        }

        /** @var array{date: string, start: string, participants: int|string, customer_name: string, customer_email: string, idempotency_key: string, duration_id?: string|null, duration_minutes?: int|null} $data */
        $data = $validator->validated();
        $participants = (int) $data['participants'];
        $max = $service->max_participants;

        if ($max !== null && $participants > $max) {
            return 'That party is larger than this service allows.';
        }

        $minutes = $this->minutes($service, $data['duration_id'] ?? null, $data['duration_minutes'] ?? null);

        if (is_string($minutes)) {
            return $minutes;
        }

        return [
            'date' => $data['date'],
            'start' => $data['start'],
            'participants' => $participants,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'idempotency_key' => $data['idempotency_key'],
            'minutes' => $minutes,
        ];
    }

    private function minutes(Service $service, mixed $durationId, mixed $durationMinutes): int|string
    {
        if ($service->durations->isNotEmpty()) {
            $chosen = $service->durations->first(function (ServiceDuration $duration) use ($durationId): bool {
                return is_string($durationId) && $duration->id === $durationId;
            });

            if (! $chosen instanceof ServiceDuration) {
                return 'Choose a rental length.';
            }

            return $chosen->duration_minutes;
        }

        if (! is_numeric($durationMinutes) || (int) $durationMinutes < 1) {
            return 'Choose a duration.';
        }

        return (int) $durationMinutes;
    }

    private function signatureExpiry(Booking $booking): CarbonImmutable
    {
        $hold = $this->openHold($booking);

        if ($hold instanceof Hold) {
            return CarbonImmutable::instance($hold->expires_at);
        }

        return CarbonImmutable::now()->addMinutes(15);
    }

    private function openHold(Booking $booking): ?Hold
    {
        $hold = Hold::query()->where('booking_id', $booking->id)->orderBy('expires_at')->first();

        if (! $hold instanceof Hold || ! $hold->expires_at->greaterThan(now())) {
            return null;
        }

        return $hold;
    }

    private function snapshot(Booking $booking): PricingSnapshot
    {
        $snapshot = PricingSnapshot::query()->where('booking_id', $booking->id)->first();
        abort_unless($snapshot instanceof PricingSnapshot, 404);

        return $snapshot;
    }

    private function terms(PricingSnapshot $snapshot): ?DisclosureVersion
    {
        if ($snapshot->disclosure_version_id === null) {
            return null;
        }

        return DisclosureVersion::query()->find($snapshot->disclosure_version_id);
    }

    /**
     * @param  array<string, mixed>  $offers
     */
    private function formResponse(string $token, Service $service, string $error, Request $request, int $status, array $offers = [], bool $offerWaitlist = false): Response
    {
        return response()->view('widget.form', [
            'token' => $token,
            'service' => $service,
            'error' => $error,
            'old' => $request->all(),
            'suggestions' => $this->suggestionLines($service, $offers),
            'offerWaitlist' => $offerWaitlist && $service->waitlist_enabled,
        ], $status);
    }

    private function slotIsFull(string $message): bool
    {
        return in_array($message, [
            'That time is no longer available.',
            'That guide is already booked.',
            'That departure is full.',
            'That private departure already has a booking.',
            'An individual asset can only be held once.',
            'A required resource is not available.',
        ], true);
    }

    private function closedMessage(OpeningHours $hours, mixed $location, CarbonImmutable $startsAt, CarbonImmutable $endsAt): ?string
    {
        if (! $location instanceof Location) {
            return null;
        }

        $open = $hours->assess($location, $startsAt, $endsAt);

        if ($open['open']) {
            return null;
        }

        return match (true) {
            $open['closed'] => 'That time is closed.',
            $open['blackout'] => 'That date is closed.',
            default => 'That time is outside opening hours.',
        };
    }

    /**
     * @param  array<string, mixed>  $offers
     * @return list<string>
     */
    private function suggestionLines(Service $service, array $offers): array
    {
        if ($offers === []) {
            return [];
        }

        $zone = $service->location instanceof Location ? $service->location->timezone : 'UTC';
        $lines = [];

        foreach ($offers['times'] ?? [] as $time) {
            if (! is_array($time) || ! is_string($time['starts_at'] ?? null) || ! is_string($time['ends_at'] ?? null)) {
                continue;
            }

            $start = CarbonImmutable::parse($time['starts_at'])->timezone($zone);
            $end = CarbonImmutable::parse($time['ends_at'])->timezone($zone);
            $lines[] = $start->format('M j, g:i A').' to '.$end->format('g:i A');
        }

        $ids = [];

        foreach ($offers['other_asset_ids'] ?? [] as $id) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        if ($ids !== []) {
            foreach (Asset::query()->whereIn('id', $ids)->orderBy('name')->pluck('name') as $name) {
                $lines[] = (string) $name;
            }
        }

        return $lines;
    }

    /**
     * @return array{url: string|null, label: string|null}
     */
    private function pickupOffer(Tenant $tenant, string $token, Booking $booking, PricingSnapshot $snapshot, ?Hold $hold): array
    {
        $setting = $this->setting($tenant);

        if (! $hold instanceof Hold || ! $setting instanceof TenantSetting || ! $setting->pay_at_pickup) {
            return ['url' => null, 'label' => null];
        }

        return [
            'url' => URL::temporarySignedRoute('widget.pickup', CarbonImmutable::instance($hold->expires_at), [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'label' => Money::format($this->checkout->amountDueAtPickup($snapshot), $snapshot->currency),
        ];
    }

    private function confirmationUrl(string $token, Booking $booking, ?Hold $hold): string
    {
        $expires = $hold instanceof Hold
            ? CarbonImmutable::instance($hold->expires_at)
            : CarbonImmutable::now()->addHour();

        return URL::temporarySignedRoute('widget.confirmation', $expires, [
            'token' => $token,
            'booking' => $booking->id,
        ]);
    }

    private function checkoutResponse(
        string $token,
        Booking $booking,
        PricingSnapshot $snapshot,
        ?Hold $hold,
        ?string $payUrl,
        ?string $pickupUrl,
        ?string $pickupLabel,
        ?string $error,
        int $status,
        string $promoValue = '',
    ): Response {
        return response()->view('widget.checkout', [
            'token' => $token,
            'booking' => $booking->load('service'),
            'snapshot' => $snapshot,
            'terms' => $this->terms($snapshot),
            'hold' => $hold,
            'payUrl' => $payUrl,
            'pickupUrl' => $pickupUrl,
            'pickupLabel' => $pickupLabel,
            'promoUrl' => $this->promoUrl($token, $booking, $hold),
            'promoValue' => $promoValue,
            'error' => $error,
        ], $status);
    }

    private function promoUrl(string $token, Booking $booking, ?Hold $hold): ?string
    {
        if (! $hold instanceof Hold) {
            return null;
        }

        return URL::temporarySignedRoute('widget.promo', CarbonImmutable::instance($hold->expires_at), [
            'token' => $token,
            'booking' => $booking->id,
        ]);
    }

    private function promoValue(PricingSnapshot $snapshot): string
    {
        $code = $snapshot->payload['promo_code'] ?? null;

        return is_string($code) ? $code : '';
    }

    private function assertBookingService(Booking $booking): void
    {
        abort_unless($booking->service_id !== '', 404);
    }
}
