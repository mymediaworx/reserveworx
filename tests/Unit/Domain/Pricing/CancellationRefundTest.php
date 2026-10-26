<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Pricing\CancellationRefund;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CancellationRefundTest extends TestCase
{
    public function test_the_longest_lead_that_still_fits_wins(): void
    {
        $tiers = [
            ['lead_minutes' => 1440, 'refund_percent' => 50],
            ['lead_minutes' => 2880, 'refund_percent' => 100],
            ['lead_minutes' => 0, 'refund_percent' => 0],
        ];

        $this->assertSame(100, CancellationRefund::percent($tiers, 2880));
        $this->assertSame(50, CancellationRefund::percent($tiers, 2879));
        $this->assertSame(50, CancellationRefund::percent($tiers, 1440));
        $this->assertSame(0, CancellationRefund::percent($tiers, 1439));
        $this->assertSame(0, CancellationRefund::percent($tiers, 0));
        $this->assertSame(0, CancellationRefund::percent($tiers, -1));
        $this->assertSame(0, CancellationRefund::percent([], 10_000));
    }

    public function test_minutes_until_the_start_stop_at_the_start(): void
    {
        $start = CarbonImmutable::parse('2026-06-17 12:00:00', 'UTC');

        $this->assertSame(2880, CancellationRefund::minutesUntil($start, CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC')));
        $this->assertSame(2879, CancellationRefund::minutesUntil($start, CarbonImmutable::parse('2026-06-15 12:00:01', 'UTC')));
        $this->assertSame(0, CancellationRefund::minutesUntil($start, $start));
        $this->assertSame(-1, CancellationRefund::minutesUntil($start, $start->addSecond()));
    }

    public function test_a_share_rounds_half_up(): void
    {
        $this->assertSame(5001, CancellationRefund::share(10_001, 50));
        $this->assertSame(10_001, CancellationRefund::share(10_001, 100));
        $this->assertSame(0, CancellationRefund::share(10_001, 0));
        $this->assertSame(0, CancellationRefund::share(1, 1));
    }

    public function test_a_share_refuses_a_percent_outside_0_to_100(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CancellationRefund::share(100, 101);
    }
}
