<?php

namespace Database\Factories;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Models\Booking;
use App\Models\BookingAddOn;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingAddOn>
 */
class BookingAddOnFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'add_on_id' => null,
            'name' => 'Cooler',
            'kind' => AddOnKind::Addon,
            'pricing' => AddOnPricing::Flat,
            'unit_amount_cents' => 1_500,
            'quantity' => 1,
            'total_cents' => 1_500,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BookingAddOn $line): void {
            $booking = Booking::withoutGlobalScope(TenantScope::class)->find($line->booking_id);

            if ($booking instanceof Booking) {
                $line->tenant_id = $booking->tenant_id;
            }
        });
    }
}
