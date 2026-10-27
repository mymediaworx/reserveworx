<?php

namespace Tests\Unit\Support;

use App\Support\LocalClock;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class LocalClockTest extends TestCase
{
    public function test_a_normal_hour_stays_a_clock_time(): void
    {
        $instant = CarbonImmutable::parse('2026-06-15 09:00:00', 'America/New_York');

        $this->assertFalse(LocalClock::repeated($instant));
        $this->assertSame('09:00', LocalClock::token($instant));
        $parsed = LocalClock::parse('2026-06-15', '09:00', 'America/New_York');
        $this->assertInstanceOf(CarbonImmutable::class, $parsed);
        $this->assertSame($instant->getTimestamp(), $parsed->getTimestamp());
    }

    public function test_the_repeated_fall_back_hour_keeps_both_offsets(): void
    {
        $early = CarbonImmutable::parse('2026-11-01 01:00:00-04:00');
        $late = CarbonImmutable::parse('2026-11-01 01:00:00-05:00');

        $this->assertTrue(LocalClock::repeated($early));
        $this->assertTrue(LocalClock::repeated($late));
        $this->assertSame('01:00-04:00', LocalClock::token($early));
        $this->assertSame('01:00-05:00', LocalClock::token($late));
        $this->assertSame($early->getTimestamp(), LocalClock::parse('2026-11-01', '01:00-04:00', 'America/New_York')?->getTimestamp());
        $this->assertSame($late->getTimestamp(), LocalClock::parse('2026-11-01', '01:00-05:00', 'America/New_York')?->getTimestamp());
        $this->assertNull(LocalClock::parse('2026-06-15', '01:00-05:00', 'America/New_York'));
        $this->assertNull(LocalClock::parse('2026-11-01', '01:00-07:00', 'America/New_York'));
        $this->assertNull(LocalClock::parse('2026-11-01', '25:00', 'America/New_York'));
        $this->assertNull(LocalClock::parse('2026-02-31', '01:00', 'America/New_York'));
        $this->assertNull(LocalClock::parse('2026-11-01', '01:00', 'Mars/Phobos'));
    }

    public function test_spring_forward_has_no_repeated_hour(): void
    {
        $before = CarbonImmutable::parse('2026-03-08 01:30:00', 'America/New_York');
        $after = CarbonImmutable::parse('2026-03-08 03:30:00', 'America/New_York');

        $this->assertFalse(LocalClock::repeated($before));
        $this->assertFalse(LocalClock::repeated($after));
        $this->assertSame('01:30', LocalClock::token($before));
        $this->assertSame('03:30', LocalClock::token($after));
    }
}
