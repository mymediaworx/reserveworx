<?php

namespace Tests\Unit\Domain\Catalog;

use App\Domain\Catalog\OperatorFeeCharge;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OperatorFeeChargeTest extends TestCase
{
    public function test_flat_per_person_and_percent_lines_round_half_up(): void
    {
        $lines = OperatorFeeCharge::lines([
            $this->fee('01FLAT', 'Admin', 'flat', 300, null),
            $this->fee('01PERSON', 'Cleaning', 'per_person', 500, null),
            $this->fee('01PCT', 'Fuel', 'percent', null, 1_000),
            $this->fee('01ODD', 'Wear', 'percent', null, 100),
            $this->fee('01FIFTEEN', 'Dock', 'percent', null, 1_500),
        ], 2, 333);

        $this->assertSame(300, $lines[0]['total_cents']);
        $this->assertSame(1, $lines[0]['quantity']);
        $this->assertSame(1_000, $lines[1]['total_cents']);
        $this->assertSame(2, $lines[1]['quantity']);
        $this->assertSame(33, $lines[2]['total_cents']);
        $this->assertSame(1, OperatorFeeCharge::total($this->fee('01ONE', 'One', 'percent', null, 100), 1, 50));
        $this->assertSame(50, $lines[4]['total_cents']);
        $this->assertSame(1_386, OperatorFeeCharge::cents($lines));
    }

    public function test_an_unknown_pricing_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A mandatory fee must be flat, per person, or a percent.');

        OperatorFeeCharge::total($this->fee('01BAD', 'Bad', 'hourly', 100, null), 1, 1_000);
    }

    /**
     * @return array{operator_fee_id: string, name: string, pricing: string, scope: string, amount_cents: int|null, percent_bps: int|null}
     */
    private function fee(string $id, string $name, string $pricing, ?int $amount, ?int $percent): array
    {
        return [
            'operator_fee_id' => $id,
            'name' => $name,
            'pricing' => $pricing,
            'scope' => 'service',
            'amount_cents' => $amount,
            'percent_bps' => $percent,
        ];
    }
}
