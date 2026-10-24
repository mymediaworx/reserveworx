<?php

namespace Database\Factories;

use App\Enums\OperatorFeePricing;
use App\Enums\OperatorFeeScope;
use App\Models\Booking;
use App\Models\BookingOperatorFee;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingOperatorFee>
 */
class BookingOperatorFeeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'operator_fee_id' => null,
            'name' => 'Cleaning',
            'scope' => OperatorFeeScope::Service,
            'pricing' => OperatorFeePricing::Flat,
            'amount_cents' => 2_500,
            'percent_bps' => null,
            'quantity' => 1,
            'total_cents' => 2_500,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BookingOperatorFee $line): void {
            $booking = Booking::withoutGlobalScope(TenantScope::class)->find($line->booking_id);

            if ($booking instanceof Booking) {
                $line->tenant_id = $booking->tenant_id;
            }
        });
    }
}
