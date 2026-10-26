<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\LocationTax;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class LocationTaxTest extends TestCase
{
    public function test_each_rate_uses_only_the_bases_it_names(): void
    {
        $assessed = LocationTax::assess([
            [
                'id' => '01STATE',
                'name' => 'State sales tax',
                'percent_bps' => '825',
                'applies_to_rentals' => 1,
                'applies_to_add_ons' => 1,
                'applies_to_fees' => 0,
            ],
            [
                'id' => '01FUEL',
                'name' => 'Fuel tax',
                'percent_bps' => 1_000,
                'applies_to_rentals' => false,
                'applies_to_add_ons' => false,
                'applies_to_fees' => true,
            ],
        ], 10_000, 2_000, 500);

        $this->assertSame(1_040, $assessed['cents']);
        $this->assertSame(990, $assessed['lines'][0]['cents']);
        $this->assertSame(12_000, $assessed['lines'][0]['basis_cents']);
        $this->assertSame(50, $assessed['lines'][1]['cents']);
        $this->assertSame(500, $assessed['lines'][1]['basis_cents']);
        $this->assertSame('Fuel tax', $assessed['lines'][1]['name']);
    }

    public function test_an_empty_list_charges_nothing(): void
    {
        $this->assertSame(['cents' => 0, 'lines' => []], LocationTax::assess([], 10_000, 2_000, 500));
    }

    public function test_one_cent_rounds_half_up(): void
    {
        $half = LocationTax::assess([$this->rule(5_000)], 1, 0, 0);
        $below = LocationTax::assess([$this->rule(4_999)], 1, 0, 0);

        $this->assertSame(1, $half['cents']);
        $this->assertSame(0, $below['cents']);
    }

    public function test_a_rate_must_name_a_base_and_stay_inside_one_hundred_percent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LocationTax::assess([$this->rule(825, false, false, false)], 100, 0, 0);
    }

    public function test_a_rate_outside_the_percent_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A tax rate must be between 0.01 and 100 percent.');

        LocationTax::assess([$this->rule(0)], 100, 0, 0);
    }

    public function test_a_negative_base_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LocationTax::assess([], -1, 0, 0);
    }

    public function test_a_booking_fee_is_added_only_to_rental_rates(): void
    {
        $assessed = LocationTax::assess([
            $this->rule(1_000, true, true, false),
            $this->rule(1_000, false, false, true),
        ], 10_000, 2_000, 500, 300);

        $this->assertSame(1_280, $assessed['cents']);
        $this->assertSame(12_300, $assessed['lines'][0]['basis_cents']);
        $this->assertSame(1_230, $assessed['lines'][0]['cents']);
        $this->assertSame(500, $assessed['lines'][1]['basis_cents']);
        $this->assertSame(50, $assessed['lines'][1]['cents']);
    }

    /**
     * @return array{id: string, name: string, percent_bps: int, applies_to_rentals: bool, applies_to_add_ons: bool, applies_to_fees: bool}
     */
    private function rule(int $basisPoints, bool $rentals = true, bool $addOns = false, bool $fees = false): array
    {
        return [
            'id' => '01RATE',
            'name' => 'Tax',
            'percent_bps' => $basisPoints,
            'applies_to_rentals' => $rentals,
            'applies_to_add_ons' => $addOns,
            'applies_to_fees' => $fees,
        ];
    }
}
