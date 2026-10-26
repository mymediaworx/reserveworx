<?php

namespace Tests\Unit\Support;

use App\Support\Display;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class DisplayTest extends TestCase
{
    public function test_a_multi_day_span_uses_the_location_zone_once(): void
    {
        $start = CarbonImmutable::parse('2026-11-03T15:00:00+00:00');
        $end = CarbonImmutable::parse('2026-11-05T15:00:00+00:00');

        $this->assertSame(
            'Tue, Nov 3, 10:00 AM – Thu, Nov 5, 10:00 AM EST',
            Display::rangeWithZone($start, $end, 'America/New_York'),
        );
        $this->assertSame(
            'Tue, Nov 3, 10:00 AM EST',
            Display::dateTimeWithZone($start, 'America/New_York'),
        );
    }

    public function test_a_utc_date_that_is_the_next_local_day_stays_on_the_local_calendar(): void
    {
        $start = CarbonImmutable::parse('2026-11-04T02:30:00Z');
        $end = CarbonImmutable::parse('2026-11-06T02:30:00Z');

        $this->assertSame(
            'Tue, Nov 3, 9:30 PM – Thu, Nov 5, 9:30 PM EST',
            Display::rangeWithZone($start, $end, 'America/New_York'),
        );
    }

    public function test_the_same_day_in_utc_names_utc_once(): void
    {
        $start = CarbonImmutable::parse('2026-06-15T17:00:00Z');
        $end = CarbonImmutable::parse('2026-06-15T18:00:00Z');

        $this->assertSame(
            'Mon, Jun 15, 5:00 PM – 6:00 PM UTC',
            Display::rangeWithZone($start, $end, 'UTC'),
        );
        $this->assertSame(
            'Mon, Jun 15, 5:30 PM UTC',
            Display::dateTimeWithZone(CarbonImmutable::parse('2026-06-15T17:30:00Z'), 'UTC'),
        );
    }

    public function test_a_daylight_saving_change_names_both_abbreviations(): void
    {
        $this->assertSame(
            'Sat, Mar 7, 10:00 AM EST – Mon, Mar 9, 11:00 AM EDT',
            Display::rangeWithZone(
                CarbonImmutable::parse('2026-03-07T15:00:00Z'),
                CarbonImmutable::parse('2026-03-09T15:00:00Z'),
                'America/New_York',
            ),
        );
        $this->assertSame(
            'Sun, Mar 8, 1:30 AM EST – 3:30 AM EDT',
            Display::rangeWithZone(
                CarbonImmutable::parse('2026-03-08T06:30:00Z'),
                CarbonImmutable::parse('2026-03-08T07:30:00Z'),
                'America/New_York',
            ),
        );
    }
}
