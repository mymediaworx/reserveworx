<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\Notifications\NotificationHooks;
use App\Services\Widget\WidgetTokens;
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

        $this->allocator->cancel($booking, false);
        $this->hooks->record(
            $booking->tenant_id,
            $booking->id,
            'booking.guest_cancelled',
            'booking.guest_cancelled:'.$booking->id,
            ['booking_id' => $booking->id],
        );

        return $this->manageResponse($token, $booking->fresh() ?? $booking, null, 200);
    }

    public function reschedule(Request $request, string $token, Booking $booking): Response
    {
        $tenant = $this->tenant($request);
        $this->tokens->findActive($token) ?? abort(404);
        $booking->load('service.location');
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

    private function match(string $reference, string $email): ?Booking
    {
        $booking = Booking::query()->with('customer')->find($reference);

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

    private function manageResponse(string $token, Booking $booking, ?string $error, int $status): Response
    {
        return response()->view('widget.manage', $this->manageData($token, $booking, $error), $status);
    }

    /**
     * @return array{token: string, booking: Booking, cancelUrl: string, rescheduleUrl: string, canCancel: bool, cancelMessage: ?string, error: ?string}
     */
    private function manageData(string $token, Booking $booking, ?string $error): array
    {
        $block = $this->cancelBlock($booking);

        return [
            'token' => $token,
            'booking' => $booking->load('service'),
            'cancelUrl' => URL::temporarySignedRoute('widget.guest-cancel', now()->addMinutes(30), [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'rescheduleUrl' => URL::temporarySignedRoute('widget.guest-reschedule', now()->addMinutes(30), [
                'token' => $token,
                'booking' => $booking->id,
            ]),
            'canCancel' => $block === null,
            'cancelMessage' => $block,
            'error' => $error,
        ];
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }
}
