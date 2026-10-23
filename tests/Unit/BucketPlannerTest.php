<?php

namespace Tests\Unit;

use App\Services\Allocation\BucketPlanner;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class BucketPlannerTest extends TestCase
{
    public function test_buckets_are_epoch_aligned_and_half_open(): void
    {
        $planner = new BucketPlanner;
        $start = CarbonImmutable::parse('2026-06-15T15:07:00Z');
        $end = CarbonImmutable::parse('2026-06-15T15:30:00Z');

        $this->assertSame([
            CarbonImmutable::parse('2026-06-15T15:00:00Z')->getTimestamp(),
            CarbonImmutable::parse('2026-06-15T15:15:00Z')->getTimestamp(),
        ], $planner->covering($start, $end, 15));

        $exact = $planner->covering(
            CarbonImmutable::parse('2026-06-15T15:00:00Z'),
            CarbonImmutable::parse('2026-06-15T15:15:00Z'),
            15,
        );

        $this->assertSame([CarbonImmutable::parse('2026-06-15T15:00:00Z')->getTimestamp()], $exact);
    }
}
