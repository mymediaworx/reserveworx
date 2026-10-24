<?php

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\AddOnCharge;
use App\Enums\AddOnPricing;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class AddOnChargeTest extends TestCase
{
    public function test_flat_per_person_and_each_prices(): void
    {
        $this->assertSame(1_500, AddOnCharge::total(AddOnPricing::Flat, 1_500, 1, 4));
        $this->assertSame(800, AddOnCharge::total(AddOnPricing::PerPerson, 200, 1, 4));
        $this->assertSame(1_000, AddOnCharge::total(AddOnPricing::Each, 500, 2, 4));
    }

    public function test_a_flat_line_cannot_take_a_quantity(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A flat add-on is charged once.');

        AddOnCharge::total(AddOnPricing::Flat, 1_500, 2, 2);
    }

    public function test_a_per_person_line_uses_the_party_size(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A per-person add-on uses the party size.');

        AddOnCharge::total(AddOnPricing::PerPerson, 200, 2, 2);
    }
}
