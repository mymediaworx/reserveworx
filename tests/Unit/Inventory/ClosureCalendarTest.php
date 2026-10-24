<?php

namespace Tests\Unit\Inventory;

use App\Models\LocationClosure;
use App\Services\Inventory\ClosureCalendar;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ClosureCalendarTest extends TestCase
{
    public function test_spring_forward_and_fall_back_use_the_location_clock(): void
    {
        $calendar = new ClosureCalendar;
        $zone = 'America/New_York';

        $springDay = $calendar->concrete($zone, true, '2026-03-08', '2026-03-08', null, null);
        $this->assertSame('2026-03-08 05:00:00', $springDay[0]->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-09 04:00:00', $springDay[1]->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(23 * 60, (int) round($springDay[0]->diffInMinutes($springDay[1])));

        $gap = $calendar->concrete($zone, false, '2026-03-08', '2026-03-08', '01:30', '03:30');
        $this->assertSame('2026-03-08 06:30:00', $gap[0]->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-08 07:30:00', $gap[1]->utc()->format('Y-m-d H:i:s'));

        $missing = $calendar->localInstant('2026-03-08', '02:30:00', $zone, false);
        $this->assertSame('2026-03-08 07:00:00', $missing->utc()->format('Y-m-d H:i:s'));

        $fallDay = $calendar->concrete($zone, true, '2026-11-01', '2026-11-01', null, null);
        $this->assertSame('2026-11-01 04:00:00', $fallDay[0]->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-02 05:00:00', $fallDay[1]->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(25 * 60, (int) round($fallDay[0]->diffInMinutes($fallDay[1])));

        $repeated = $calendar->concrete($zone, false, '2026-11-01', '2026-11-01', '01:00', '02:00');
        $this->assertSame('2026-11-01 05:00:00', $repeated[0]->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 07:00:00', $repeated[1]->utc()->format('Y-m-d H:i:s'));

        $spring = $this->closure(false, true, '2026-03-08', '2026-03-08');
        $this->assertTrue($calendar->covers($spring, $zone, CarbonImmutable::parse('2026-03-08 06:30:00', 'UTC'), CarbonImmutable::parse('2026-03-08 07:30:00', 'UTC')));
        $this->assertFalse($calendar->covers($spring, $zone, CarbonImmutable::parse('2026-03-08 03:00:00', 'UTC'), CarbonImmutable::parse('2026-03-08 04:00:00', 'UTC')));
        $this->assertFalse($calendar->covers($spring, $zone, CarbonImmutable::parse('2026-03-09 04:00:00', 'UTC'), CarbonImmutable::parse('2026-03-09 05:00:00', 'UTC')));

        $hours = $this->closure(false, false, '2026-03-08', '2026-03-08', '01:30:00', '03:30:00');
        $this->assertTrue($calendar->covers($hours, $zone, CarbonImmutable::parse('2026-03-08 06:45:00', 'UTC'), CarbonImmutable::parse('2026-03-08 07:15:00', 'UTC')));
        $this->assertFalse($calendar->covers($hours, $zone, CarbonImmutable::parse('2026-03-08 07:30:00', 'UTC'), CarbonImmutable::parse('2026-03-08 08:00:00', 'UTC')));

        $fallback = $this->closure(false, false, '2026-11-01', '2026-11-01', '01:00:00', '02:00:00');
        $this->assertTrue($calendar->covers($fallback, $zone, CarbonImmutable::parse('2026-11-01 05:30:00', 'UTC'), CarbonImmutable::parse('2026-11-01 06:00:00', 'UTC')));
        $this->assertTrue($calendar->covers($fallback, $zone, CarbonImmutable::parse('2026-11-01 06:30:00', 'UTC'), CarbonImmutable::parse('2026-11-01 06:45:00', 'UTC')));
        $this->assertFalse($calendar->covers($fallback, $zone, CarbonImmutable::parse('2026-11-01 07:00:00', 'UTC'), CarbonImmutable::parse('2026-11-01 07:30:00', 'UTC')));
    }

    public function test_a_yearly_closure_repeats_and_a_one_off_does_not(): void
    {
        $calendar = new ClosureCalendar;
        $zone = 'America/New_York';
        $yearly = $this->closure(true, true, '2026-12-25', '2026-12-25');
        $once = $this->closure(false, true, '2026-12-25', '2026-12-25');
        $nextChristmas = [CarbonImmutable::parse('2027-12-25 15:00:00', 'UTC'), CarbonImmutable::parse('2027-12-25 16:00:00', 'UTC')];

        $this->assertTrue($calendar->covers($yearly, $zone, $nextChristmas[0], $nextChristmas[1]));
        $this->assertFalse($calendar->covers($once, $zone, $nextChristmas[0], $nextChristmas[1]));
        $this->assertFalse($calendar->covers($yearly, $zone, CarbonImmutable::parse('2027-12-26 15:00:00', 'UTC'), CarbonImmutable::parse('2027-12-26 16:00:00', 'UTC')));

        $wrapped = $this->closure(true, true, '2026-12-31', '2027-01-01');
        $this->assertTrue($calendar->covers($wrapped, $zone, CarbonImmutable::parse('2028-01-01 15:00:00', 'UTC'), CarbonImmutable::parse('2028-01-01 16:00:00', 'UTC')));
        $this->assertFalse($calendar->covers($wrapped, $zone, CarbonImmutable::parse('2028-01-02 15:00:00', 'UTC'), CarbonImmutable::parse('2028-01-02 16:00:00', 'UTC')));

        $leapDay = $this->closure(true, true, '2024-02-29', '2024-02-29');
        $this->assertTrue($calendar->covers($leapDay, $zone, CarbonImmutable::parse('2028-02-29 15:00:00', 'UTC'), CarbonImmutable::parse('2028-02-29 16:00:00', 'UTC')));
        $this->assertFalse($calendar->covers($leapDay, $zone, CarbonImmutable::parse('2027-02-28 15:00:00', 'UTC'), CarbonImmutable::parse('2027-02-28 16:00:00', 'UTC')));
        $this->assertFalse($calendar->covers($leapDay, $zone, CarbonImmutable::parse('2027-03-01 15:00:00', 'UTC'), CarbonImmutable::parse('2027-03-01 16:00:00', 'UTC')));
    }

    public function test_a_category_or_resource_limit_does_not_close_the_whole_location(): void
    {
        $calendar = new ClosureCalendar;
        $wide = new LocationClosure(['resource_category_id' => null, 'resource_type' => null, 'resource_id' => null]);
        $category = new LocationClosure(['resource_category_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'resource_type' => null, 'resource_id' => null]);
        $pool = new LocationClosure(['resource_category_id' => null, 'resource_type' => 'pool', 'resource_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW']);

        $this->assertTrue($calendar->applies($wide, null, null, null));
        $this->assertTrue($calendar->applies($wide, 'pool', '01ARZ3NDEKTSV4RRFFQ69G5FAW', '01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        $this->assertFalse($calendar->applies($category, null, null, null));
        $this->assertTrue($calendar->applies($category, 'asset', '01ARZ3NDEKTSV4RRFFQ69G5FAX', '01ARZ3NDEKTSV4RRFFQ69G5FAV'));
        $this->assertFalse($calendar->applies($category, 'asset', '01ARZ3NDEKTSV4RRFFQ69G5FAX', '01ARZ3NDEKTSV4RRFFQ69G5FAY'));
        $this->assertTrue($calendar->applies($pool, 'pool', '01ARZ3NDEKTSV4RRFFQ69G5FAW', null));
        $this->assertFalse($calendar->applies($pool, 'pool', '01ARZ3NDEKTSV4RRFFQ69G5FAX', null));
        $this->assertFalse($calendar->applies($pool, null, null, null));
    }

    private function closure(bool $yearly, bool $allDay, string $startsOn, string $endsOn, ?string $startsLocal = null, ?string $endsLocal = null): LocationClosure
    {
        return new LocationClosure([
            'recurs_yearly' => $yearly,
            'all_day' => $allDay,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'starts_local' => $startsLocal,
            'ends_local' => $endsLocal,
        ]);
    }
}
