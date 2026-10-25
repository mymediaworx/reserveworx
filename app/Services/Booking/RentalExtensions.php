<?php

namespace App\Services\Booking;

use App\Enums\PaymentStatus;
use App\Models\Booking;
use App\Models\StripeAccount;
use App\Services\Allocation\AllocationUnavailableException;
use App\Services\Allocation\Allocator;
use App\Services\AuditLogger;
use App\Services\Payments\CheckoutService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Extends a rental, then prices the extra time with the existing engine.
 * A higher price on a booking already paid by card is charged through Stripe
 * as a price increase: the booking fee is the percentage on that increase only.
 */
final class RentalExtensions
{
    public function __construct(
        private readonly Allocator $allocator,
        private readonly CheckoutService $checkout,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{booking: Booking, offers: array<string, mixed>, checkout: array{payment_id: string, url: string|null, amount_cents: int, fee_cents: int}|null, charge_message: string|null}
     */
    public function request(
        Booking $booking,
        CarbonImmutable $newEnd,
        ?string $assetId,
        string $successUrl,
        string $cancelUrl,
        bool $requireCardForIncrease,
        ?Request $request = null,
    ): array {
        $newEnd = $newEnd->utc();

        if ($requireCardForIncrease && $this->priceWouldIncrease($booking, $newEnd) && ! $this->canChargeIncrease($booking)) {
            throw new AllocationUnavailableException('This extension costs more. Pay the booking by card before adding time.');
        }

        $result = $this->allocator->extend($booking, $newEnd, $assetId);

        if ($result['offers'] !== []) {
            return [
                'booking' => $result['booking'],
                'offers' => $result['offers'],
                'checkout' => null,
                'charge_message' => null,
            ];
        }

        $extended = $result['booking'];
        $checkout = null;
        $chargeMessage = null;

        if ($this->priceWouldIncrease($extended, CarbonImmutable::instance($extended->ends_at)->utc())) {
            if ($this->canChargeIncrease($extended)) {
                try {
                    $started = $this->checkout->startIncreaseCheckout($extended, $successUrl, $cancelUrl);
                } catch (AllocationUnavailableException $exception) {
                    $chargeMessage = $exception->getMessage();
                    $started = null;
                }

                if ($started !== null) {
                    $checkout = [
                        'payment_id' => $started['payment']->id,
                        'url' => $started['url'],
                        'amount_cents' => $started['payment']->amount_cents,
                        'fee_cents' => $started['payment']->fee_cents,
                    ];

                    if ($started['url'] !== null) {
                        $this->audit->log('payment.increase_checkout_started', $started['payment'], [
                            'booking_id' => $extended->id,
                            'amount_cents' => $started['payment']->amount_cents,
                            'fee_cents' => $started['payment']->fee_cents,
                        ], request: $request);
                    }
                }
            } else {
                $chargeMessage = 'The extra time is saved. The higher price is charged by card, with the booking fee on that increase only, once this booking is paid by card.';
            }
        }

        return [
            'booking' => $extended,
            'offers' => [],
            'checkout' => $checkout,
            'charge_message' => $chargeMessage,
        ];
    }

    private function priceWouldIncrease(Booking $booking, CarbonImmutable $newEnd): bool
    {
        $saved = $booking->ends_at;
        $booking->ends_at = Carbon::instance($newEnd);

        try {
            return $this->checkout->pendingPriceChange($booking) === 'increase';
        } finally {
            $booking->ends_at = $saved;
        }
    }

    private function canChargeIncrease(Booking $booking): bool
    {
        if (! in_array($booking->payment_status, [PaymentStatus::DepositPaid, PaymentStatus::Paid], true)) {
            return false;
        }

        $account = StripeAccount::query()->where('tenant_id', $booking->tenant_id)->first();

        return $account instanceof StripeAccount && $account->canCharge();
    }
}
