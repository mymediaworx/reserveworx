<?php

namespace App\Http\Controllers;

use App\Domain\Fees\Disclosures\Money;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Pricing\GroupPriceCopy;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\VariantPrice;
use App\Enums\AddOnKind;
use App\Enums\BookingStatus;
use App\Enums\FeePayer;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AddOn;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Departure;
use App\Models\DisclosureVersion;
use App\Models\Hold;
use App\Models\Location;
use App\Models\Participant;
use App\Models\Payment;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Service;
use App\Models\ServiceDuration;
use App\Models\ServiceVariant;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\WidgetToken;
use App\Services\Allocation\AllocationIntent;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Allocation\IdempotencyConflictException;
use App\Services\AuditLogger;
use App\Services\Booking\CancellationPolicies;
use App\Services\Booking\NamedParty;
use App\Services\Booking\SlotFinder;
use App\Services\Booking\Waitlist;
use App\Services\Catalog\BookingAddOnLines;
use App\Services\Catalog\BookingOperatorFeeLines;
use App\Services\CheckIn\CheckInPass;
use App\Services\Inventory\OpeningHours;
use App\Services\Notifications\WaitlistJoinedMail;
use App\Services\Payments\CheckoutService;
use App\Services\Pricing\BookingPricer;
use App\Services\Waivers\WaiverForm;
use App\Services\Waivers\WaiverLinks;
use App\Services\Widget\WidgetTokens;
use App\Support\Display;
use App\Support\DisplayZone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
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
        private readonly BookingAddOnLines $addOnLines,
        private readonly CheckInPass $checkInPass,
        private readonly SlotFinder $slots,
        private readonly CancellationPolicies $policies,
        private readonly NamedParty $party,
        private readonly WaiverForm $waivers,
    ) {}

    public function services(Request $request, string $token): View
    {
        $tenant = $this->tenant($request);
        $this->activeToken($token);
        $setting = $this->setting($tenant);

        $services = Service::query()->with(['location', 'durations'])->atListedLocation()->orderBy('name')->get()->map(function (Service $service) use ($setting): array {
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
        $this->requireListedLocation($service);
        $service->load(['location', 'durations', 'variants']);

        $chosen = $request->only(['date', 'duration_id', 'duration_minutes', 'participants', 'start', 'variant_id']);
        $setting = $this->setting($this->tenant($request));

        return view('widget.form', [
            'token' => $token,
            'service' => $service,
            'error' => null,
            'old' => $chosen,
            'suggestions' => [],
            'offerWaitlist' => false,
            'departureChoices' => $this->departureChoices($service, $chosen, $setting),
            'slots' => $this->openTimes($service, $chosen, $setting),
            'today' => $this->localToday($service),
            'timeZoneNote' => $this->timeZoneNote($service),
            'addOns' => $this->catalogAddOns(),
            'currency' => $this->currency(),
            'groupPrice' => $this->groupPrice($service, $chosen, $setting),
            'hasPublicGroupTier' => $this->hasPublicGroupTier($service),
            'options' => $this->optionChoices($service, $setting),
            'lengthChoices' => $this->lengthChoices($service, $setting),
        ]);
    }

    public function store(Request $request, string $token, Service $service): Response|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->activeToken($token);
        $this->requireListedLocation($service);
        $this->trimPostedPhone($request);
        $service->load(['location', 'durations']);
        $validated = $service->kind === ServiceKind::Tour
            ? $this->validateTour($request, $service)
            : $this->validateBooking($request, $service);

        if (is_string($validated)) {
            return $this->formResponse($token, $service, $validated, $request, 422);
        }

        $interval = $this->bookedInterval($service, $validated);

        if (is_string($interval)) {
            return $this->formResponse($token, $service, $interval, $request, 422);
        }

        $addOns = $this->chosenAddOns($request, $validated['participants']);

        if (is_string($addOns)) {
            return $this->formResponse($token, $service, $addOns, $request, 422);
        }

        try {
            $this->previewAt($service, $this->setting($tenant), $validated['participants'], $interval['minutes'], $interval['local'], $this->optionOverride($service, $validated['variant_id'] ?? null));
            $booking = $this->allocator->hold(new AllocationIntent(
                $tenant->id,
                $service->id,
                $interval['starts_at'],
                $interval['ends_at'],
                $validated['participants'],
                $validated['idempotency_key'],
                $validated['customer_name'],
                $validated['customer_email'],
                $interval['departure_id'],
                customerPhone: $this->postedPhone($validated),
                addOns: $addOns,
                variantId: $this->chosenVariantId($validated),
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
        $this->requireListedLocation($service);
        $service->load(['location', 'durations']);
        abort_unless($service->waitlist_enabled, 404);

        $validated = $service->kind === ServiceKind::Tour
            ? $this->validateTour($request, $service)
            : $this->validateBooking($request, $service);

        if (is_string($validated)) {
            return $this->formResponse($token, $service, $validated, $request, 422, [], true);
        }

        $phoneError = Validator::make($request->all(), [
            'customer_phone' => ['nullable', 'string', 'max:255'],
        ])->errors()->first();

        if ($phoneError !== '') {
            return $this->formResponse($token, $service, $phoneError, $request, 422, [], true);
        }

        $interval = $this->bookedInterval($service, $validated);

        if (is_string($interval)) {
            return $this->formResponse($token, $service, $interval, $request, 422, [], true);
        }

        $closed = $this->closedMessage($hours, $service->location, $interval['starts_at'], $interval['ends_at']);

        if ($closed !== null) {
            return $this->formResponse($token, $service, $closed, $request, 409);
        }

        $hint = $allocator->hint(new AllocationIntent(
            $tenant->id,
            $service->id,
            $interval['starts_at'],
            $interval['ends_at'],
            $validated['participants'],
            null,
            $validated['customer_name'],
            $validated['customer_email'],
            $interval['departure_id'],
            variantId: $this->chosenVariantId($validated),
        ));

        if ($hint['available']) {
            return $this->formResponse($token, $service, 'That time is open. Review the price and hold it.', $request, 409);
        }

        $phone = $request->input('customer_phone');
        $entry = $waitlist->join(
            $tenant->id,
            $service->id,
            $interval['departure_id'],
            $service->location_id,
            $interval['starts_at'],
            $interval['ends_at'],
            $validated['participants'],
            $validated['customer_name'],
            $validated['customer_email'],
            null,
            $validated['idempotency_key'],
            $this->chosenVariantId($validated),
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
            'when' => Display::dateTimeWithZone($interval['local'], $this->visibleZone($service->location)),
            'participants' => $entry->participants,
            'email' => (string) ($entry->customer_email ?? $validated['customer_email']),
            'emailed' => $emailed,
            'already' => ! $created,
        ]);
    }

    public function checkout(Request $request, string $token, Booking $booking): View|RedirectResponse
    {
        $this->activeToken($token);
        $this->assertBookingService($booking);

        if ($this->alreadyBooked($booking)) {
            return redirect()->to($this->confirmationUrl($token, $booking));
        }
        $snapshot = $this->snapshot($booking);
        $hold = $this->openHold($booking);

        $pickup = $this->pickupOffer($this->tenant($request), $token, $booking, $snapshot, $hold);

        return $this->checkoutView(
            $token,
            $booking,
            $snapshot,
            $hold,
            $hold instanceof Hold ? URL::temporarySignedRoute('widget.pay', $hold->expires_at, [
                'token' => $token,
                'booking' => $booking->id,
            ]) : null,
            $pickup['url'],
            $pickup['label'],
            null,
            $this->promoValue($snapshot),
        );
    }

    public function storeParticipant(Request $request, string $token, Booking $booking): Response|RedirectResponse
    {
        $this->activeToken($token);
        $this->assertBookingService($booking);

        return $this->saveParticipant($request, $token, $booking);
    }

    public function signWaivers(Request $request, string $token, Booking $booking): Response|RedirectResponse
    {
        $this->activeToken($token);
        $this->assertBookingService($booking);
        abort_unless(in_array($booking->status, [BookingStatus::Held, BookingStatus::PendingPayment, BookingStatus::Confirmed], true), 404);

        if ($this->alreadyBooked($booking)) {
            return redirect()->to($this->confirmationUrl($token, $booking));
        }

        try {
            $this->waivers->sign($request, $booking);
        } catch (ValidationException $exception) {
            $fresh = $booking->fresh() ?? $booking;
            $snapshot = $this->snapshot($fresh);
            $hold = $this->openHold($fresh);

            return $this->checkoutResponse(
                $token,
                $fresh,
                $snapshot,
                $hold,
                null,
                null,
                null,
                null,
                422,
                $this->promoValue($snapshot),
                waiverError: $this->firstError($exception),
                waiverOld: $this->waiverOld($request),
            );
        }

        $fresh = $booking->fresh() ?? $booking;
        $hold = $this->openHold($fresh);
        $expires = $hold instanceof Hold
            ? CarbonImmutable::instance($hold->expires_at)
            : CarbonImmutable::now()->addMinutes(15);

        return redirect()->to(URL::temporarySignedRoute('widget.checkout', $expires, [
            'token' => $token,
            'booking' => $fresh->id,
        ]));
    }

    public function removeParticipant(Request $request, string $token, Booking $booking, Participant $participant): Response|RedirectResponse
    {
        $this->activeToken($token);
        $this->assertBookingService($booking);
        $return = $this->partyReturn($request);

        try {
            $this->party->remove($booking, $participant, $request);
        } catch (AllocationUnavailableException $exception) {
            return $this->partyFailure($request, $token, $booking, $return, $exception->getMessage(), 409);
        }

        return redirect()->to($this->partyReturnUrl($token, $booking->fresh() ?? $booking, $return));
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
            return redirect()->to($this->confirmationUrl($token, $fresh));
        }

        $snapshot = $this->snapshot($fresh);

        if (! $hold instanceof Hold) {
            return $this->checkoutResponse($token, $fresh, $snapshot, null, null, null, null, 'This hold has expired.', 409);
        }

        if ($this->waivers->blocking($fresh)) {
            return $this->refusedUntilSigned($token, $fresh, $snapshot, $hold);
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

        return redirect()->to($this->confirmationUrl($token, $fresh));
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

        if ($this->waivers->blocking($booking)) {
            return $this->refusedUntilSigned($token, $booking, $snapshot, $hold);
        }

        $expires = CarbonImmutable::instance($hold->expires_at);

        try {
            $sessionEnds = $this->checkout->reserveCheckoutWindow($booking);
            $linksEnd = ($sessionEnds ?? $expires)->addMinutes(CheckoutService::WEBHOOK_GRACE_MINUTES);
            $success = $this->confirmationUrl($token, $booking);
            $cancel = URL::temporarySignedRoute('widget.checkout', $linksEnd, [
                'token' => $token,
                'booking' => $booking->id,
            ]);
            $started = $this->checkout->startCheckout($booking->load('service'), $success, $cancel, null, $sessionEnds);
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
        $snapshot = PricingSnapshot::query()->where('booking_id', $fresh->id)->first();
        $payload = $snapshot instanceof PricingSnapshot ? $snapshot->payload : [];
        $taxLines = is_array($payload['tax_lines'] ?? null) ? $payload['tax_lines'] : [];

        return $this->confirmationView(
            $token,
            $fresh,
            $taxLines,
            $snapshot instanceof PricingSnapshot && $snapshot->currency !== '' ? $snapshot->currency : 'USD',
            is_numeric($payload['services_cents'] ?? null) ? (int) $payload['services_cents'] : 0,
            is_array($payload['add_ons'] ?? null) ? $payload['add_ons'] : [],
        );
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

    private function requireListedLocation(Service $service): void
    {
        abort_unless(Service::query()->atListedLocation()->whereKey($service->getKey())->exists(), 404);
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
        $zone = $this->visibleZone($service->location);

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
     * @return array{total_cents: int, fee_included: bool, currency: string, party_bands: list<array<string, mixed>>}
     */
    private function previewAt(Service $service, ?TenantSetting $setting, int $participants, int $minutes, CarbonImmutable $on, ?int $baseOverrideCents = null): array
    {
        $channel = FeeChannel::Direct;
        $rate = $setting instanceof TenantSetting
            ? FeeRate::fromTenantSetting($setting, $channel)
            : FeeRate::defaultFor($channel);
        $payer = $setting instanceof TenantSetting ? $setting->fee_payer : FeePayer::CustomerPaid;
        $rules = VariantPrice::overrideBase(PriceRule::inputsFor($service->id), $baseOverrideCents);
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
            'party_bands' => $priced->partyBands,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{label: string, detail: string, total_cents: int, currency: string, fee_included: bool}|null
     */
    private function groupPrice(Service $service, array $input, ?TenantSetting $setting): ?array
    {
        if (! is_numeric($input['participants'] ?? null) || (int) $input['participants'] < 1 || ! $this->hasPublicGroupTier($service)) {
            return null;
        }

        $service->loadMissing(['location', 'durations']);
        $zone = $this->visibleZone($service->location);
        $date = $input['date'] ?? null;
        $date = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1
            ? $date
            : CarbonImmutable::now($zone)->toDateString();
        $clock = $input['start'] ?? null;
        $clock = is_string($clock) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $clock) === 1 ? $clock : '12:00';
        $on = CarbonImmutable::parse($date.' '.$clock, $zone);
        $minutes = 60;
        $override = null;

        if ($service->kind !== ServiceKind::Tour) {
            $option = $this->selectedOption($service, $input['variant_id'] ?? null);
            $fallback = $service->durations->first();
            $resolved = $option instanceof ServiceVariant
                ? $option->duration_minutes
                : $this->minutes(
                    $service,
                    $input['duration_id'] ?? null,
                    $input['duration_minutes'] ?? ($fallback instanceof ServiceDuration ? $fallback->duration_minutes : 60),
                    $input['variant_id'] ?? null,
                );
            $minutes = is_int($resolved) ? $resolved : ($fallback instanceof ServiceDuration ? $fallback->duration_minutes : 60);
            $override = $option instanceof ServiceVariant ? $option->price_override_cents : null;
        }

        try {
            $preview = $this->previewAt($service, $setting, (int) $input['participants'], $minutes, $on, $override);
        } catch (InvalidArgumentException) {
            return null;
        }

        $detail = GroupPriceCopy::detail($preview['party_bands'], true);

        if ($detail === null) {
            return null;
        }

        return [
            'label' => 'Group price',
            'detail' => $detail,
            'total_cents' => $preview['total_cents'],
            'currency' => $preview['currency'],
            'fee_included' => $preview['fee_included'],
        ];
    }

    private function hasPublicGroupTier(Service $service): bool
    {
        return PriceRule::query()
            ->where('service_id', $service->id)
            ->whereNull('corporate_account_id')
            ->whereNotNull('min_participants')
            ->exists();
    }

    /**
     * A tour joins the scheduled departure at the chosen start. The departure's
     * own length is the booking length; a free duration does not apply.
     *
     * @return array{participants: int, customer_name: string, customer_email: string, customer_phone: ?string, idempotency_key: string, minutes: int, starts_at: CarbonImmutable, ends_at: CarbonImmutable, departure_id: string, variant_id: ?string}|string
     */
    private function validateTour(Request $request, Service $service): array|string
    {
        $validator = Validator::make($request->all(), [
            'departure_id' => ['nullable', 'string'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'start' => ['nullable', 'date_format:H:i'],
            'participants' => ['required', 'integer', 'min:1'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'variant_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return (string) $validator->errors()->first();
        }

        /** @var array{departure_id?: string|null, date?: string|null, start?: string|null, participants: int|string, customer_name: string, customer_email: string, customer_phone?: string|null, idempotency_key: string, variant_id?: string|null} $data */
        $data = $validator->validated();
        $participants = (int) $data['participants'];
        $max = $service->max_participants;

        if ($max !== null && $participants > $max) {
            return 'That party is larger than this service allows.';
        }

        $option = $this->selectedOption($service, $data['variant_id'] ?? null);

        if (is_string($option)) {
            return $option;
        }

        $postedId = $data['departure_id'] ?? null;
        $startsAt = $this->postedStart($service, $data['date'] ?? null, $data['start'] ?? null);
        $departure = $this->slots->matchingDeparture(
            $service,
            is_string($postedId) && $postedId !== '' ? $postedId : null,
            $startsAt,
        );

        if (! $departure instanceof Departure) {
            return 'Choose a departure.';
        }

        $starts = CarbonImmutable::instance($departure->starts_at)->utc();
        $ends = CarbonImmutable::instance($departure->ends_at)->utc();
        $minutes = (int) round($starts->diffInMinutes($ends));

        if ($minutes < 1) {
            return 'Choose a departure.';
        }

        return [
            'participants' => $participants,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'customer_phone' => $this->blankPhone($data['customer_phone'] ?? null),
            'idempotency_key' => $data['idempotency_key'],
            'minutes' => $minutes,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'departure_id' => $departure->id,
            'variant_id' => $option instanceof ServiceVariant ? $option->id : null,
        ];
    }

    private function postedStart(Service $service, mixed $date, mixed $start): ?CarbonImmutable
    {
        if (! is_string($date) || $date === '' || ! is_string($start) || $start === '') {
            return null;
        }

        $zone = $this->visibleZone($service->location);
        $local = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$start, $zone);

        return $local instanceof CarbonImmutable ? $local->utc() : null;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{starts_at: CarbonImmutable, ends_at: CarbonImmutable, departure_id: string|null, minutes: int, local: CarbonImmutable}|string
     */
    private function bookedInterval(Service $service, array $validated): array|string
    {
        $startsAt = $validated['starts_at'] ?? null;
        $endsAt = $validated['ends_at'] ?? null;
        $minutes = $validated['minutes'] ?? null;
        $departureId = $validated['departure_id'] ?? null;
        $zone = $this->visibleZone($service->location);

        if ($startsAt instanceof CarbonImmutable && $endsAt instanceof CarbonImmutable && is_int($minutes)) {
            return [
                'starts_at' => $startsAt->utc(),
                'ends_at' => $endsAt->utc(),
                'departure_id' => is_string($departureId) && $departureId !== '' ? $departureId : null,
                'minutes' => $minutes,
                'local' => $startsAt->timezone($zone),
            ];
        }

        $date = $validated['date'] ?? null;
        $start = $validated['start'] ?? null;

        if (! is_string($date) || ! is_string($start) || ! is_int($minutes)) {
            return 'Choose a valid date and time.';
        }

        $local = CarbonImmutable::createFromFormat('Y-m-d H:i', $date.' '.$start, $zone);

        if (! $local instanceof CarbonImmutable) {
            return 'Choose a valid date and time.';
        }

        return [
            'starts_at' => $local->utc(),
            'ends_at' => $local->utc()->addMinutes($minutes),
            'departure_id' => null,
            'minutes' => $minutes,
            'local' => $local,
        ];
    }

    /**
     * A tour with options keeps the all-in price on those options. A tour with
     * none shows the existing quote on each departure, for the party on the
     * form. This does not write a quote.
     *
     * @param  array<string, mixed>  $input
     * @return list<array{id: string, label: string}>
     */
    private function departureChoices(Service $service, array $input = [], ?TenantSetting $setting = null): array
    {
        if ($service->kind !== ServiceKind::Tour) {
            return [];
        }

        $service->loadMissing('variants');
        $departures = $this->slots->upcoming($service);
        $counts = Departure::headcountsFor($departures);
        $zone = $this->visibleZone($service->location);
        $participants = is_numeric($input['participants'] ?? null) ? max(1, (int) $input['participants']) : 1;
        $choices = [];

        foreach ($departures as $departure) {
            $when = CarbonImmutable::instance($departure->starts_at)->timezone($zone);
            $label = $when->format('D, M j, g:i A').' '.$when->format('T');

            if ($departure->private) {
                $label .= ' · Private';
            } elseif (is_int($departure->seats)) {
                $left = max(0, $departure->seats - ($counts[$departure->id] ?? 0));
                $label .= ' · '.$left.' seats left';
            }

            if ($service->variants->isEmpty()) {
                $label .= $this->departurePriceLabel($service, $setting, $participants, $departure, $when);
            }

            $choices[] = [
                'id' => $departure->id,
                'label' => $label,
            ];
        }

        return $choices;
    }

    private function departurePriceLabel(Service $service, ?TenantSetting $setting, int $participants, Departure $departure, CarbonImmutable $when): string
    {
        $starts = CarbonImmutable::instance($departure->starts_at);
        $ends = CarbonImmutable::instance($departure->ends_at);
        $minutes = max(1, (int) $starts->diffInMinutes($ends, true));
        $quote = $this->quotedTotal($service, $setting, $participants, $minutes, $when, null);

        if ($quote['total_cents'] === null) {
            return '';
        }

        $label = ' · '.Money::format($quote['total_cents'], $quote['currency']);

        if ($quote['fee_included']) {
            $label .= ' · Booking fee included';
        }

        return $label;
    }

    /**
     * @return array{date: string, start: string, participants: int, customer_name: string, customer_email: string, customer_phone: ?string, idempotency_key: string, minutes: int, variant_id: ?string}|string
     */
    private function validateBooking(Request $request, Service $service): array|string
    {
        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'participants' => ['required', 'integer', 'min:1'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['required', 'string', 'max:80'],
            'duration_id' => ['nullable', 'string'],
            'duration_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
            'variant_id' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return (string) $validator->errors()->first();
        }

        /** @var array{date: string, start: string, participants: int|string, customer_name: string, customer_email: string, customer_phone?: string|null, idempotency_key: string, duration_id?: string|null, duration_minutes?: int|null, variant_id?: string|null} $data */
        $data = $validator->validated();
        $participants = (int) $data['participants'];
        $max = $service->max_participants;

        if ($max !== null && $participants > $max) {
            return 'That party is larger than this service allows.';
        }

        $option = $this->selectedOption($service, $data['variant_id'] ?? null);
        $minutes = $this->minutes($service, $data['duration_id'] ?? null, $data['duration_minutes'] ?? null, $data['variant_id'] ?? null);

        if (is_string($minutes)) {
            return $minutes;
        }

        return [
            'date' => $data['date'],
            'start' => $data['start'],
            'participants' => $participants,
            'customer_name' => $data['customer_name'],
            'customer_email' => $data['customer_email'],
            'customer_phone' => $this->blankPhone($data['customer_phone'] ?? null),
            'idempotency_key' => $data['idempotency_key'],
            'minutes' => $minutes,
            'variant_id' => $option instanceof ServiceVariant ? $option->id : null,
        ];
    }

    /**
     * A service with options uses the chosen option's length. A service without
     * one still uses a named rental length, or the posted minutes.
     */
    private function minutes(Service $service, mixed $durationId, mixed $durationMinutes, mixed $variantId = null): int|string
    {
        $option = $this->selectedOption($service, $variantId);

        if (is_string($option)) {
            return $option;
        }

        if ($option instanceof ServiceVariant) {
            return $option->duration_minutes;
        }

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

    /**
     * Null when this service has no options. An error string when the guest
     * has to choose one and the posted id is missing or belongs elsewhere.
     */
    private function selectedOption(Service $service, mixed $variantId): ServiceVariant|string|null
    {
        $service->loadMissing('variants');

        if ($service->variants->isEmpty()) {
            return null;
        }

        if (! is_string($variantId) || $variantId === '') {
            return 'Choose an option.';
        }

        $chosen = $service->variants->first(fn (ServiceVariant $variant): bool => $variant->id === $variantId);

        return $chosen instanceof ServiceVariant ? $chosen : 'That option is not on this service.';
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function chosenVariantId(array $validated): ?string
    {
        $id = $validated['variant_id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function optionOverride(Service $service, mixed $variantId): ?int
    {
        $option = $this->selectedOption($service, $variantId);

        return $option instanceof ServiceVariant ? $option->price_override_cents : null;
    }

    /**
     * Prices shown beside the option radios. This uses the same preview as the
     * service list and does not write a quote.
     *
     * @return list<array{id: string, name: string, duration_minutes: int, total_cents: int|null, fee_included: bool, currency: string}>
     */
    private function optionChoices(Service $service, ?TenantSetting $setting): array
    {
        $service->loadMissing('variants');
        $choices = [];
        $currency = $setting instanceof TenantSetting ? $setting->currency : 'USD';

        foreach ($service->variants->sortBy('name') as $variant) {
            $preview = $this->choicePreview($service, $setting, $variant->duration_minutes, $variant->price_override_cents, $currency);
            $currency = $preview['currency'];
            $choices[] = [
                'id' => $variant->id,
                'name' => $variant->name,
                'duration_minutes' => $variant->duration_minutes,
                'total_cents' => $preview['total_cents'],
                'fee_included' => $preview['fee_included'],
                'currency' => $currency,
            ];
        }

        return $choices;
    }

    /**
     * Prices shown beside the length radios. This uses the same preview as the
     * service list and does not write a quote.
     *
     * @return list<array{id: string, name: string, duration_minutes: int, total_cents: int|null, fee_included: bool, currency: string}>
     */
    private function lengthChoices(Service $service, ?TenantSetting $setting): array
    {
        $service->loadMissing('durations');
        $choices = [];
        $currency = $setting instanceof TenantSetting ? $setting->currency : 'USD';

        foreach ($service->durations as $duration) {
            $preview = $this->choicePreview($service, $setting, $duration->duration_minutes, null, $currency);
            $currency = $preview['currency'];
            $choices[] = [
                'id' => $duration->id,
                'name' => $duration->name,
                'duration_minutes' => $duration->duration_minutes,
                'total_cents' => $preview['total_cents'],
                'fee_included' => $preview['fee_included'],
                'currency' => $currency,
            ];
        }

        return $choices;
    }

    /**
     * @return array{total_cents: int|null, fee_included: bool, currency: string}
     */
    private function choicePreview(Service $service, ?TenantSetting $setting, int $minutes, ?int $baseOverrideCents, string $currency): array
    {
        try {
            $preview = $this->previewAt(
                $service,
                $setting,
                max(1, (int) ($service->min_participants ?? 1)),
                $minutes,
                CarbonImmutable::now($this->visibleZone($service->location)),
                $baseOverrideCents,
            );

            return [
                'total_cents' => $preview['total_cents'],
                'fee_included' => $preview['fee_included'],
                'currency' => $preview['currency'],
            ];
        } catch (InvalidArgumentException) {
            return [
                'total_cents' => null,
                'fee_included' => false,
                'currency' => $currency,
            ];
        }
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
        $setting = $this->setting($this->tenant($request));
        $input = $request->all();

        return response()->view('widget.form', [
            'token' => $token,
            'service' => $service,
            'error' => $error,
            'old' => $input,
            'suggestions' => $this->suggestionLines($service, $offers),
            'offerWaitlist' => $offerWaitlist && $service->waitlist_enabled,
            'departureChoices' => $this->departureChoices($service, $input, $setting),
            'slots' => $this->openTimes($service, $input, $setting),
            'today' => $this->localToday($service),
            'timeZoneNote' => $this->timeZoneNote($service),
            'addOns' => $this->catalogAddOns(),
            'currency' => $this->currency(),
            'groupPrice' => $this->groupPrice($service, $input, $setting),
            'hasPublicGroupTier' => $this->hasPublicGroupTier($service),
            'options' => $this->optionChoices($service, $setting),
            'lengthChoices' => $this->lengthChoices($service, $setting),
        ], $status);
    }

    /**
     * Start times for the chosen date, length, and party, from the same slot
     * finder staff use. Null until a date and length are chosen. Every time is
     * still only a hint; the hold rechecks it under lock (#135).
     *
     * @param  array<string, mixed>  $input
     * @return list<array{value: string, label: string, available: bool, closed: bool, total_cents: int|null, currency: string, fee_included: bool}>|null
     */
    private function openTimes(Service $service, array $input, ?TenantSetting $setting = null): ?array
    {
        if ($service->kind === ServiceKind::Tour || ! $service->location instanceof Location) {
            return null;
        }

        $date = $input['date'] ?? null;

        if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return null;
        }

        $today = $this->localToday($service);

        if ($date < $today || $date > CarbonImmutable::parse($today)->addYear()->toDateString()) {
            return [];
        }

        $variantId = $input['variant_id'] ?? null;
        $minutes = $this->minutes($service, $input['duration_id'] ?? null, $input['duration_minutes'] ?? null, $variantId);

        if (is_string($minutes)) {
            return null;
        }

        $participants = is_numeric($input['participants'] ?? null) ? max(1, (int) $input['participants']) : 1;
        $zone = $this->visibleZone($service->location);
        $override = $this->optionOverride($service, $variantId);
        $times = [];

        foreach ($this->slots->rentalSlots($service, $date, $minutes, $participants, CarbonImmutable::now(), false, is_string($variantId) && $variantId !== '' ? $variantId : null) as $slot) {
            $when = $slot['starts_at']->timezone($zone);
            $quote = $this->quotedTotal($service, $setting, $participants, $minutes, $when, $override);
            $times[] = [
                'value' => $when->format('H:i'),
                'label' => $slot['label'],
                'available' => $slot['available'],
                'closed' => $slot['closed'],
                'total_cents' => $quote['total_cents'],
                'currency' => $quote['currency'],
                'fee_included' => $quote['fee_included'],
            ];
        }

        return $times;
    }

    /**
     * The quote already calculated for this start. Nothing is stored.
     *
     * @return array{total_cents: int|null, fee_included: bool, currency: string}
     */
    private function quotedTotal(Service $service, ?TenantSetting $setting, int $participants, int $minutes, CarbonImmutable $on, ?int $baseOverrideCents): array
    {
        $currency = $setting instanceof TenantSetting ? $setting->currency : 'USD';

        try {
            $preview = $this->previewAt($service, $setting, $participants, $minutes, $on, $baseOverrideCents);

            return [
                'total_cents' => $preview['total_cents'],
                'fee_included' => $preview['fee_included'],
                'currency' => $preview['currency'],
            ];
        } catch (InvalidArgumentException) {
            return [
                'total_cents' => null,
                'fee_included' => false,
                'currency' => $currency,
            ];
        }
    }

    private function visibleZone(?Location $location): string
    {
        return DisplayZone::forLocation($location);
    }

    private function timeZoneNote(Service $service): string
    {
        return 'Times are in '.$this->visibleZone($service->location).'.';
    }

    private function whenLabel(Booking $booking): string
    {
        $booking->loadMissing('location');

        return Display::rangeWithZone(
            $booking->starts_at,
            $booking->ends_at,
            DisplayZone::forLocation($booking->location),
        );
    }

    private function localToday(Service $service): string
    {
        $zone = $this->visibleZone($service->location);

        return CarbonImmutable::now($zone)->toDateString();
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

        $zone = $this->visibleZone($service->location);
        $lines = [];

        foreach ($offers['times'] ?? [] as $time) {
            if (! is_array($time) || ! is_string($time['starts_at'] ?? null) || ! is_string($time['ends_at'] ?? null)) {
                continue;
            }

            $start = CarbonImmutable::parse($time['starts_at']);
            $end = CarbonImmutable::parse($time['ends_at']);
            $lines[] = Display::rangeWithZone($start, $end, $zone);
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

    /**
     * The confirmation page carries the check-in code the guest shows on
     * arrival, so its link lasts until a week after the booking ends, and at
     * least a day. It used to expire with the hold, about 15 minutes.
     */
    private function confirmationUrl(string $token, Booking $booking): string
    {
        $afterVisit = CarbonImmutable::instance($booking->ends_at)->addDays(7);
        $minimum = CarbonImmutable::now()->addDay();

        return URL::temporarySignedRoute('widget.confirmation', $afterVisit->greaterThan($minimum) ? $afterVisit : $minimum, [
            'token' => $token,
            'booking' => $booking->id,
        ]);
    }

    /**
     * A booking that is confirmed, or is paid or settling, no longer belongs on
     * the checkout page. Showing "hold expired" there invited a second booking.
     */
    private function alreadyBooked(Booking $booking): bool
    {
        $fresh = $booking->fresh() ?? $booking;

        return in_array($fresh->status, [BookingStatus::Confirmed, BookingStatus::NoShow, BookingStatus::PaymentException], true)
            || Payment::query()->where('booking_id', $fresh->id)->where('status', 'paid')->exists();
    }

    /**
     * @param  array{name?: string, is_minor?: bool, guardian_name?: string}  $partyOld
     */
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
        ?string $partyError = null,
        array $partyOld = [],
        ?string $waiverError = null,
        array $waiverOld = [],
    ): Response {
        return response($this->checkoutView(
            $token,
            $booking,
            $snapshot,
            $hold,
            $payUrl,
            $pickupUrl,
            $pickupLabel,
            $error,
            $promoValue,
            $partyError,
            $partyOld,
            $waiverError,
            $waiverOld,
        ), $status);
    }

    /**
     * @param  array{name?: string, is_minor?: bool, guardian_name?: string}  $partyOld
     * @param  array{signer_name?: string, signer_minor?: bool, guardian?: array<string, string>, agree?: array<string, string>}  $waiverOld
     */
    private function checkoutView(
        string $token,
        Booking $booking,
        PricingSnapshot $snapshot,
        ?Hold $hold,
        ?string $payUrl,
        ?string $pickupUrl,
        ?string $pickupLabel,
        ?string $error,
        string $promoValue = '',
        ?string $partyError = null,
        array $partyOld = [],
        ?string $waiverError = null,
        array $waiverOld = [],
    ): View {
        $booking->load(['service', 'variant', 'namedParticipants', 'customer']);
        $presented = $this->waivers->present($booking);
        $unsigned = $hold instanceof Hold && $presented['required'] && ! $presented['complete'];
        $lockedPay = $unsigned && $payUrl !== null;
        $lockedPickup = $unsigned && $pickupUrl !== null;

        if ($unsigned) {
            $payUrl = null;
            $pickupUrl = null;
            $pickupLabel = null;
        }

        $expires = $hold instanceof Hold
            ? CarbonImmutable::instance($hold->expires_at)
            : CarbonImmutable::now()->addMinutes(15);

        return view('widget.checkout', [
            'token' => $token,
            'booking' => $booking,
            'snapshot' => $snapshot,
            'terms' => $this->terms($snapshot),
            'hold' => $hold,
            'payUrl' => $payUrl,
            'pickupUrl' => $pickupUrl,
            'pickupLabel' => $pickupLabel,
            'promoUrl' => $this->promoUrl($token, $booking, $hold),
            'promoValue' => $promoValue,
            'cancellationLines' => $this->policies->sentencesFor($booking->tenant_id),
            'whenLabel' => $this->whenLabel($booking),
            'error' => $error,
            'payLocked' => $lockedPay,
            'pickupLocked' => $lockedPickup,
            'waiverRequired' => $presented['required'],
            'waiverComplete' => $presented['complete'],
            'waiverVersions' => $presented['waivers'],
            'waiverPeople' => $presented['people'],
            'waiverSignUrl' => URL::temporarySignedRoute('widget.waivers', $expires, [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'waiverError' => $waiverError,
            'waiverOld' => $this->waiverFields($waiverOld),
            ...$this->partyState($token, $booking, 'checkout', $partyError, $partyOld),
        ]);
    }

    /**
     * @param  array{signer_name?: string, signer_minor?: bool, guardian?: array<string, string>, agree?: array<string, string>}  $old
     * @return array{signer_name: string, signer_minor: bool, guardian: array<string, string>, agree: array<string, string>}
     */
    private function waiverFields(array $old): array
    {
        return [
            'signer_name' => is_string($old['signer_name'] ?? null) ? $old['signer_name'] : '',
            'signer_minor' => (bool) ($old['signer_minor'] ?? false),
            'guardian' => $old['guardian'] ?? [],
            'agree' => $old['agree'] ?? [],
        ];
    }

    /**
     * @return array{signer_name: string, signer_minor: bool, guardian: array<string, string>, agree: array<string, string>}
     */
    private function waiverOld(Request $request): array
    {
        $guardian = [];
        $agree = [];
        $incomingGuardian = $request->input('guardian');
        $incomingAgree = $request->input('agree');

        if (is_array($incomingGuardian)) {
            foreach ($incomingGuardian as $key => $value) {
                if (is_string($value)) {
                    $guardian[(string) $key] = $value;
                }
            }
        }

        if (is_array($incomingAgree)) {
            foreach ($incomingAgree as $key => $value) {
                if (is_string($value) || is_numeric($value)) {
                    $agree[(string) $key] = (string) $value;
                }
            }
        }

        return [
            'signer_name' => is_string($request->input('signer_name')) ? $request->input('signer_name') : '',
            'signer_minor' => $request->boolean('signer_minor'),
            'guardian' => $guardian,
            'agree' => $agree,
        ];
    }

    private function refusedUntilSigned(string $token, Booking $booking, PricingSnapshot $snapshot, Hold $hold): Response
    {
        return $this->checkoutResponse(
            $token,
            $booking,
            $snapshot,
            $hold,
            null,
            null,
            null,
            'Sign the waiver before you pay.',
            409,
            $this->promoValue($snapshot),
        );
    }

    /**
     * @param  array<mixed>  $taxLines
     * @param  array<mixed>  $addOnLines
     * @param  array{name?: string, is_minor?: bool, guardian_name?: string}  $partyOld
     */
    private function confirmationView(
        string $token,
        Booking $booking,
        array $taxLines,
        string $currency,
        int $serviceCents = 0,
        array $addOnLines = [],
        ?string $partyError = null,
        array $partyOld = [],
    ): View {
        $booking->load(['service', 'variant', 'namedParticipants', 'customer']);

        return view('widget.confirmation', [
            'booking' => $booking,
            'whenLabel' => $this->whenLabel($booking),
            'checkInQr' => $this->checkInPass->svg($booking),
            'checkInUrl' => $this->checkInPass->url($booking),
            'waiverUrl' => in_array($booking->status, [BookingStatus::Held, BookingStatus::PendingPayment, BookingStatus::Confirmed], true) ? app(WaiverLinks::class)->urlIfNeeded($booking) : null,
            'taxLines' => $taxLines,
            'currency' => $currency,
            'serviceCents' => $serviceCents,
            'addOnLines' => $addOnLines,
            ...$this->partyState($token, $booking, 'confirmation', $partyError, $partyOld),
        ]);
    }

    private function saveParticipant(Request $request, string $token, Booking $booking): Response|RedirectResponse
    {
        $return = $this->partyReturn($request);
        $validator = Validator::make($request->all(), [
            'name' => ['nullable', 'string', 'max:120'],
            'is_minor' => ['sometimes', 'boolean'],
            'guardian_name' => ['nullable', 'string', 'max:120'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
            'return' => ['nullable', 'in:checkout,confirmation'],
        ]);

        if ($validator->fails()) {
            return $this->partyFailure($request, $token, $booking, $return, (string) $validator->errors()->first(), 422);
        }

        try {
            $this->party->add(
                $booking,
                (string) $request->input('name'),
                $request->boolean('is_minor'),
                $request->input('guardian_name'),
                $request->input('idempotency_key'),
                $request,
            );
        } catch (ValidationException $exception) {
            return $this->partyFailure($request, $token, $booking, $return, $this->firstError($exception), 422);
        } catch (AllocationUnavailableException|IdempotencyConflictException $exception) {
            return $this->partyFailure($request, $token, $booking, $return, $exception->getMessage(), 409);
        }

        return redirect()->to($this->partyReturnUrl($token, $booking->fresh() ?? $booking, $return));
    }

    /**
     * @param  array{name?: string, is_minor?: bool, guardian_name?: string}  $partyOld
     * @return array{partyAddUrl: string, partyReturn: string, partyError: ?string, partyOld: array{name: string, is_minor: bool, guardian_name: string}, partyRemoveUrls: array<string, string>}
     */
    private function partyState(string $token, Booking $booking, string $return, ?string $partyError, array $partyOld): array
    {
        $booking->loadMissing('namedParticipants');
        $expires = $this->partyLinkExpiry($booking, $return);
        $removeUrls = [];

        foreach ($booking->namedParticipants as $person) {
            $removeUrls[$person->id] = URL::temporarySignedRoute('widget.party.remove', $expires, [
                'token' => $token,
                'booking' => $booking->id,
                'participant' => $person->id,
            ]);
        }

        return [
            'partyAddUrl' => URL::temporarySignedRoute('widget.party.store', $expires, [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'partyReturn' => $return,
            'partyError' => $partyError,
            'partyOld' => [
                'name' => is_string($partyOld['name'] ?? null) ? $partyOld['name'] : '',
                'is_minor' => (bool) ($partyOld['is_minor'] ?? false),
                'guardian_name' => is_string($partyOld['guardian_name'] ?? null) ? $partyOld['guardian_name'] : '',
            ],
            'partyRemoveUrls' => $removeUrls,
        ];
    }

    private function partyReturn(Request $request): string
    {
        return $request->input('return') === 'confirmation' ? 'confirmation' : 'checkout';
    }

    private function partyLinkExpiry(Booking $booking, string $return): CarbonImmutable
    {
        if ($return === 'confirmation') {
            $afterVisit = CarbonImmutable::instance($booking->ends_at)->addDays(7);
            $minimum = CarbonImmutable::now()->addDay();

            return $afterVisit->greaterThan($minimum) ? $afterVisit : $minimum;
        }

        $hold = $this->openHold($booking);

        if ($hold instanceof Hold) {
            return CarbonImmutable::instance($hold->expires_at);
        }

        return CarbonImmutable::now()->addMinutes(15);
    }

    private function partyReturnUrl(string $token, Booking $booking, string $return): string
    {
        if ($return === 'confirmation' || $this->alreadyBooked($booking)) {
            return $this->confirmationUrl($token, $booking);
        }

        return URL::temporarySignedRoute('widget.checkout', $this->partyLinkExpiry($booking, 'checkout'), [
            'token' => $token,
            'booking' => $booking->id,
        ]);
    }

    private function partyFailure(Request $request, string $token, Booking $booking, string $return, string $error, int $status): Response
    {
        $fresh = $booking->fresh() ?? $booking;
        $old = [
            'name' => is_string($request->input('name')) ? $request->input('name') : '',
            'is_minor' => $request->boolean('is_minor'),
            'guardian_name' => is_string($request->input('guardian_name')) ? $request->input('guardian_name') : '',
        ];

        if ($return === 'confirmation' || $this->alreadyBooked($fresh)) {
            $snapshot = PricingSnapshot::query()->where('booking_id', $fresh->id)->first();
            $payload = $snapshot instanceof PricingSnapshot ? $snapshot->payload : [];
            $taxLines = is_array($payload['tax_lines'] ?? null) ? $payload['tax_lines'] : [];

            return response($this->confirmationView(
                $token,
                $fresh,
                $taxLines,
                $snapshot instanceof PricingSnapshot && $snapshot->currency !== '' ? $snapshot->currency : 'USD',
                is_numeric($payload['services_cents'] ?? null) ? (int) $payload['services_cents'] : 0,
                is_array($payload['add_ons'] ?? null) ? $payload['add_ons'] : [],
                $error,
                $old,
            ), $status);
        }

        $snapshot = $this->snapshot($fresh);
        $hold = $this->openHold($fresh);
        $pickup = $this->pickupOffer($this->tenant($request), $token, $fresh, $snapshot, $hold);

        return $this->checkoutResponse(
            $token,
            $fresh,
            $snapshot,
            $hold,
            $hold instanceof Hold ? URL::temporarySignedRoute('widget.pay', CarbonImmutable::instance($hold->expires_at), [
                'token' => $token,
                'booking' => $fresh->id,
            ]) : null,
            $pickup['url'],
            $pickup['label'],
            null,
            $status,
            $this->promoValue($snapshot),
            $error,
            $old,
        );
    }

    private function firstError(ValidationException $exception): string
    {
        foreach ($exception->errors() as $messages) {
            foreach ($messages as $message) {
                if (is_string($message) && $message !== '') {
                    return $message;
                }
            }
        }

        return 'Check the name and try again.';
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

    /**
     * Active equipment and add-ons in this workspace. Equipment is listed first.
     *
     * @return Collection<int, AddOn>
     */
    private function catalogAddOns(): Collection
    {
        return AddOn::query()
            ->where('active', true)
            ->get()
            ->sort(function (AddOn $left, AddOn $right): int {
                $kind = ($left->kind === AddOnKind::Equipment ? 0 : 1) <=> ($right->kind === AddOnKind::Equipment ? 0 : 1);

                if ($kind !== 0) {
                    return $kind;
                }

                $name = strcasecmp($left->name, $right->name);

                if ($name !== 0) {
                    return $name;
                }

                return $left->id <=> $right->id;
            })
            ->values();
    }

    /**
     * Lines the guest chose on the public form. An empty choice is allowed.
     * A row that is inactive, from another workspace, or outside its quantity
     * is refused before a hold is created.
     *
     * @return list<array{add_on_id: string, name: string, kind: string, pricing: string, unit_amount_cents: int, quantity: int, total_cents: int}>|string
     */
    private function chosenAddOns(Request $request, int $participants): array|string
    {
        if (! $request->exists('add_ons')) {
            return [];
        }

        $raw = $request->input('add_ons');

        if (! is_array($raw)) {
            return 'Choose equipment and add-ons from the list.';
        }

        $requested = [];

        foreach ($raw as $id => $quantity) {
            if (! is_string($id) || $id === '' || is_array($quantity)) {
                return 'Choose equipment and add-ons from the list.';
            }

            $amount = is_numeric($quantity) ? (int) $quantity : 0;

            if ($amount < 1) {
                continue;
            }

            $requested[] = [
                'id' => $id,
                'quantity' => $amount,
            ];
        }

        if (count($requested) > 20) {
            return 'Choose at most 20 add-ons.';
        }

        try {
            return $this->addOnLines->price($participants, $requested);
        } catch (ValidationException $exception) {
            $message = $exception->validator->errors()->first();

            return $message !== '' ? $message : 'Choose equipment and add-ons from the list.';
        }
    }

    private function currency(): string
    {
        $setting = TenantSetting::query()->first();

        return $setting instanceof TenantSetting && $setting->currency !== ''
            ? $setting->currency
            : 'USD';
    }

    private function trimPostedPhone(Request $request): void
    {
        $phone = $request->input('customer_phone');

        if (is_string($phone)) {
            $request->merge(['customer_phone' => trim($phone)]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function postedPhone(array $validated): ?string
    {
        return $this->blankPhone($validated['customer_phone'] ?? null);
    }

    private function blankPhone(mixed $phone): ?string
    {
        return is_string($phone) && $phone !== '' ? $phone : null;
    }
}
