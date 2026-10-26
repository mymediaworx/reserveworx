<?php

namespace App\Http\Controllers;

use App\Enums\GhlMessageKind;
use App\Enums\OperationalStatus;
use App\Enums\ServiceKind;
use App\Models\Asset;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Booking\CancellationPolicies;
use App\Services\Booking\CancellationSettlement;
use App\Services\Booking\RentalExtensions;
use App\Services\Booking\TourDepartureChoices;
use App\Services\Integrations\GhlMessages;
use App\Services\Notifications\NotificationHooks;
use App\Services\Widget\WidgetTokens;
use App\Support\BookingReference;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;

class GuestBookingController extends Controller
{
    public function __construct(
        private readonly WidgetTokens $tokens,
        private readonly Allocator $allocator,
        private readonly NotificationHooks $hooks,
        private readonly RentalExtensions $extensions,
        private readonly GhlMessages $ghlMessages,
        private readonly CancellationSettlement $settlement,
        private readonly CancellationPolicies $policies,
        private readonly TourDepartureChoices $departures,
    ) {}

    public function lookupForm(Request $request, string $token): View
    {
        $this->tokens->findActive($token) ?? abort(404);

        return view('widget.lookup', ['token' => $token, 'error' => null]);
    }

    public function lookup(Request $request, string $token): Response|RedirectResponse
    {
        $this->tokens->findActive($token) ?? abort(404);
        $validator = Validator::make($request->all(), [
            'reference' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->view('widget.lookup', [
                'token' => $token,
                'error' => (string) $validator->errors()->first(),
            ], 422);
        }

        $booking = $this->match($validator->validated()['reference'], $validator->validated()['email']);

        if (! $booking instanceof Booking) {
            return response()->view('widget.lookup', [
                'token' => $token,
                'error' => 'No booking matches that reference and email.',
            ], 404);
        }

        return redirect()->to(URL::temporarySignedRoute('widget.manage', now()->addMinutes(30), [
            'token' => $token,
            'booking' => $booking->id,
        ]));
    }

    public function manage(Request $request, string $token, Booking $booking): View
    {
        $this->tokens->findActive($token) ?? abort(404);

        return view('widget.manage', $this->manageData($token, $booking, null));
    }

    public function cancel(Request $request, string $token, Booking $booking): Response
    {
        $this->tokens->findActive($token) ?? abort(404);
        $block = $this->cancelBlock($booking);

        if ($block !== null) {
            return $this->manageResponse($token, $booking, $block, 409);
        }

        $settlement = $this->settlement->refundCustomerCancel($booking, CarbonImmutable::now());

        if ($settlement['problem'] !== null) {
            return $this->manageResponse($token, $booking, 'This booking was not cancelled: '.$settlement['problem'], 409);
        }

        $this->allocator->cancel($booking, false);
        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.guest_cancelled',
            'booking.guest_cancelled:'.$booking->id,
            ['booking_id' => $booking->id],
        );
        $this->ghlMessages->queueBooking($booking->fresh() ?? $booking, GhlMessageKind::GuestCancelled);

        return $this->manageResponse($token, $booking->fresh() ?? $booking, null, 200);
    }

    public function reschedule(Request $request, string $token, Booking $booking): Response
    {
        $tenant = $this->tenant($request);
        $this->tokens->findActive($token) ?? abort(404);
        $booking->load('service.location');

        if ($booking->departure_id !== null && $booking->departure_id !== '') {
            $validator = Validator::make($request->all(), [
                'departure_id' => ['required', 'string'],
            ]);

            if ($validator->fails()) {
                return $this->manageResponse($token, $booking, (string) $validator->errors()->first(), 422);
            }

            $departureId = $validator->validated()['departure_id'];

            try {
                $updated = $this->allocator->moveToDeparture($booking, is_string($departureId) ? $departureId : '');
            } catch (AllocationUnavailableException $exception) {
                return $this->manageResponse($token, $booking, $exception->getMessage(), 409);
            }

            $this->hooks->record(
                $tenant->id,
                $updated->id,
                'booking.guest_rescheduled',
                'booking.guest_rescheduled:'.$updated->id.':'.$updated->starts_at->utc()->format('YmdHi'),
                ['booking_id' => $updated->id],
            );

            return $this->manageResponse($token, $updated, null, 200);
        }

        $validator = Validator::make($request->all(), [
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
        ]);

        if ($validator->fails()) {
            return $this->manageResponse($token, $booking, (string) $validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $service = $booking->service;
        $location = $service instanceof Service ? $service->location : null;
        $zone = $location instanceof Location ? $location->timezone : 'UTC';
        $starts = CarbonImmutable::createFromFormat('Y-m-d H:i', $data['date'].' '.$data['start'], $zone);

        if (! $starts instanceof CarbonImmutable) {
            return $this->manageResponse($token, $booking, 'Choose a valid date and time.', 422);
        }

        try {
            $updated = $this->allocator->reschedule(
                $booking,
                $starts->utc(),
                $starts->utc()->addMinutes((int) $data['duration_minutes']),
            );
        } catch (AllocationUnavailableException $exception) {
            return $this->manageResponse($token, $booking, $exception->getMessage(), 409);
        }

        $this->hooks->record(
            $tenant->id,
            $updated->id,
            'booking.guest_rescheduled',
            'booking.guest_rescheduled:'.$updated->id.':'.$updated->starts_at->utc()->format('YmdHi'),
            ['booking_id' => $updated->id],
        );

        return $this->manageResponse($token, $updated, null, 200);
    }

    public function extend(Request $request, string $token, Booking $booking): Response|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->tokens->findActive($token) ?? abort(404);
        $booking->load('service.location');
        $validator = Validator::make($request->all(), [
            'extra_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10080'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'asset_id' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);

        if ($validator->fails()) {
            return $this->manageResponse($token, $booking, (string) $validator->errors()->first(), 422);
        }

        $data = $validator->validated();
        $extra = $data['extra_minutes'] ?? null;
        $endsAt = $data['ends_at'] ?? null;

        if (is_numeric($extra)) {
            $newEnd = CarbonImmutable::instance($booking->ends_at)->utc()->addMinutes((int) $extra)->utc();
        } elseif (is_string($endsAt) && $endsAt !== '') {
            $newEnd = CarbonImmutable::parse($endsAt)->utc();
        } else {
            return $this->manageResponse($token, $booking, 'Choose how much longer you need the rental.', 422);
        }

        $assetId = is_string($data['asset_id'] ?? null) && $data['asset_id'] !== '' ? $data['asset_id'] : null;
        $back = URL::temporarySignedRoute('widget.manage', now()->addMinutes(30), [
            'token' => $token,
            'booking' => $booking->id,
        ]);

        try {
            $result = $this->extensions->request($booking, $newEnd, $assetId, $back, $back, true, $request);
        } catch (AllocationUnavailableException $exception) {
            return $this->manageResponse($token, $booking, $exception->getMessage(), 409);
        }

        if ($result['offers'] !== []) {
            return $this->manageResponse($token, $booking, 'That time is no longer available.', 409, $result['offers'], $newEnd);
        }

        $updated = $result['booking'];
        $checkoutUrl = $result['checkout']['url'] ?? null;

        if (is_string($checkoutUrl) && $checkoutUrl !== '') {
            return redirect()->away($checkoutUrl);
        }

        $this->hooks->record(
            $tenant->id,
            $updated->id,
            'booking.guest_extended',
            'booking.guest_extended:'.$updated->id.':'.$updated->ends_at->utc()->format('YmdHi'),
            ['booking_id' => $updated->id],
        );

        $message = $result['charge_message'];

        return $this->manageResponse($token, $updated, $message, $message === null ? 200 : 409);
    }

    private function match(string $reference, string $email): ?Booking
    {
        $reference = trim($reference);
        $booking = BookingReference::isShort($reference)
            ? Booking::query()
                ->with('customer')
                ->where('id', 'like', '%'.strtolower($reference))
                ->whereHas('customer', fn ($query) => $query->where('email', $email))
                ->orderByDesc('starts_at')
                ->first()
            : Booking::query()->with('customer')->find(strtolower($reference));

        if (! $booking instanceof Booking || ! $booking->customer instanceof Customer) {
            return null;
        }

        if (strcasecmp((string) $booking->customer->email, $email) !== 0) {
            return null;
        }

        return $booking;
    }

    private function cancelBlock(Booking $booking): ?string
    {
        if (! in_array($booking->status->value, ['held', 'confirmed'], true)) {
            return 'This booking can no longer be cancelled here.';
        }

        $setting = TenantSetting::query()->where('tenant_id', $booking->tenant_id)->first();
        $minutes = $setting instanceof TenantSetting ? $setting->guest_cancel_before_minutes : null;

        if ($minutes === null) {
            return 'Online cancellation is not available. Contact the operator.';
        }

        $cutoff = CarbonImmutable::instance($booking->starts_at)->utc()->subMinutes($minutes);

        if (CarbonImmutable::now()->greaterThanOrEqualTo($cutoff)) {
            return 'This booking is inside the cancellation window.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $offers
     */
    private function manageResponse(string $token, Booking $booking, ?string $error, int $status, ?array $offers = null, ?CarbonImmutable $requestedEnd = null): Response
    {
        return response()->view('widget.manage', $this->manageData($token, $booking, $error, $offers, $requestedEnd), $status);
    }

    /**
     * @param  array<string, mixed>|null  $offers
     * @return array{token: string, booking: Booking, cancelUrl: string, rescheduleUrl: string, extendUrl: string, canCancel: bool, canExtend: bool, cancelMessage: ?string, cancelNote: string, cancellationLines: list<string>, error: ?string, offers: array<string, mixed>|null, offerAssets: list<array{id: string, name: string}>, requestedEnd: ?string, departures: list<array{id: string, label: string, available: bool, note: string}>}
     */
    private function manageData(string $token, Booking $booking, ?string $error, ?array $offers = null, ?CarbonImmutable $requestedEnd = null): array
    {
        $block = $this->cancelBlock($booking);
        $booking->load('service');
        $ids = is_array($offers['other_asset_ids'] ?? null) ? $offers['other_asset_ids'] : [];
        $names = $ids === [] ? collect() : Asset::query()->whereIn('id', $ids)->pluck('name', 'id');
        $offerAssets = [];

        foreach ($ids as $id) {
            if (! is_string($id) || $id === '') {
                continue;
            }

            $offerAssets[] = [
                'id' => $id,
                'name' => (string) ($names[$id] ?? 'Asset'),
            ];
        }

        return [
            'token' => $token,
            'booking' => $booking,
            'cancelUrl' => URL::temporarySignedRoute('widget.guest-cancel', now()->addMinutes(30), [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'rescheduleUrl' => URL::temporarySignedRoute('widget.guest-reschedule', now()->addMinutes(30), [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'extendUrl' => URL::temporarySignedRoute('widget.guest-extend', now()->addMinutes(30), [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'canCancel' => $block === null,
            'canExtend' => $this->canExtend($booking),
            'cancelMessage' => $block,
            'cancelNote' => $this->policies->cancelNote($booking, CarbonImmutable::now()),
            'cancellationLines' => $this->policies->sentencesFor($booking->tenant_id),
            'error' => $error,
            'offers' => $offers,
            'offerAssets' => $offerAssets,
            'requestedEnd' => $requestedEnd?->utc()->format('Y-m-d\TH:i:s\Z'),
            'departures' => $this->departures->forBooking($booking),
        ];
    }

    private function canExtend(Booking $booking): bool
    {
        $service = $booking->service;

        return $booking->status->value === 'confirmed'
            && $service instanceof Service
            && $service->kind === ServiceKind::Rental
            && ! in_array($booking->operational_status, [OperationalStatus::Returned, OperationalStatus::Completed], true);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
