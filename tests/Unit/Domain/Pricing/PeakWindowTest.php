<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Pricing\PriceRuleInput;
use App\Domain\Pricing\PricingEngine;
use App\Enums\FeePayer;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PeakWindowTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PricingEngine(new FeeCalculator);
    }

    public function test_a_peak_replaces_the_weekday_rate_for_minutes_inside_the_window(): void
    {
        $snapshot = $this->price([
            new PriceRuleInput('base', 10_000, 'weekday'),
            new PriceRuleInput('base', 20_000, 'weekend'),
            $this->peak('base', 16_000, 10 * 60, 14 * 60, weekdays: [1, 2, 3, 4, 5]),
        ], CarbonImmutable::parse('2026-06-15 10:00:00', 'America/New_York'), 120);

        $this->assertSame(16_000, $snapshot['services_cents']);
        $this->assertSame('10:00', $snapshot['time_windows'][0]['local_start']);
        $this->assertSame('14:00', $snapshot['time_windows'][0]['local_end']);
        $this->assertSame(120, $snapshot['time_windows'][0]['minutes']);
        $this->assertSame([1, 2, 3, 4, 5], $snapshot['time_windows'][0]['weekdays']);
    }

    public function test_a_booking_that_crosses_the_window_is_the_minute_weighted_average(): void
    {
        $snapshot = $this->price([
            new PriceRuleInput('base', 10_000, 'weekday'),
            $this->peak('base', 16_000, 10 * 60, 14 * 60),
        ], CarbonImmutable::parse('2026-06-15 09:30:00', 'America/New_York'), 90);

        $this->assertSame(14_000, $snapshot['services_cents']);
        $this->assertSame(60, $snapshot['time_windows'][0]['minutes']);
    }

    public function test_the_end_minute_is_outside_the_window_and_the_start_minute_is_inside(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            $this->peak('base', 20_000, 11 * 60, 12 * 60),
        ];

        $before = $this->price($rules, CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'), 60);
        $inside = $this->price($rules, CarbonImmutable::parse('2026-06-15 11:00:00', 'UTC'), 60);

        $this->assertSame(10_000, $before['services_cents']);
        $this->assertSame([], $before['time_windows']);
        $this->assertSame(20_000, $inside['services_cents']);
    }

    public function test_an_overnight_window_covers_both_sides_of_midnight(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            $this->peak('base', 20_000, 22 * 60, 2 * 60),
        ];
        $inside = $this->price($rules, CarbonImmutable::parse('2026-06-15 23:00:00', 'UTC'), 120);
        $split = $this->price($rules, CarbonImmutable::parse('2026-06-15 21:00:00', 'UTC'), 120);

        $this->assertSame(20_000, $inside['services_cents']);
        $this->assertSame(15_000, $split['services_cents']);
    }

    public function test_a_date_bounded_peak_beats_an_everyday_peak_and_an_earlier_rule_wins_a_tie(): void
    {
        $on = CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC');
        $seasonal = $this->price([
            $this->peak('base', 15_000, 10 * 60, 12 * 60),
            $this->peak('base', 18_000, 10 * 60, 12 * 60, startsOn: '2026-06-01', endsOn: '2026-06-30'),
        ], $on, 60);
        $outside = $this->price([
            $this->peak('base', 15_000, 10 * 60, 12 * 60),
            $this->peak('base', 18_000, 10 * 60, 12 * 60, startsOn: '2026-07-01', endsOn: '2026-07-31'),
        ], $on, 60);
        $tie = $this->price([
            $this->peak('base', 11_100, 10 * 60, 12 * 60),
            $this->peak('base', 22_200, 10 * 60, 12 * 60),
        ], $on, 60);

        $this->assertSame(18_000, $seasonal['services_cents']);
        $this->assertSame(15_000, $outside['services_cents']);
        $this->assertSame(11_100, $tie['services_cents']);
        $this->assertSame(11_100, $tie['time_windows'][0]['amount_cents']);
    }

    public function test_a_weekday_list_beats_a_weekday_window_and_does_not_apply_on_another_day(): void
    {
        $open = new PriceRuleInput('base', 10_000);
        $listed = $this->peak('base', 17_000, 16 * 60, 17 * 60, weekdays: [1]);
        $weekday = $this->peak('base', 15_000, 16 * 60, 17 * 60, 'weekday');
        $fridayOnly = [
            $open,
            $this->peak('base', 20_000, 16 * 60, 17 * 60, weekdays: [5]),
        ];

        $monday = $this->price([$open, $listed, $weekday], CarbonImmutable::parse('2026-06-15 16:00:00', 'UTC'), 60);
        $thursday = $this->price($fridayOnly, CarbonImmutable::parse('2026-06-18 16:00:00', 'UTC'), 60);
        $friday = $this->price($fridayOnly, CarbonImmutable::parse('2026-06-19 16:00:00', 'UTC'), 60);

        $this->assertSame(17_000, $monday['services_cents']);
        $this->assertSame(10_000, $thursday['services_cents']);
        $this->assertSame(20_000, $friday['services_cents']);
    }

    public function test_a_partial_peak_with_no_off_peak_rate_charges_only_the_covered_share(): void
    {
        $snapshot = $this->price([
            $this->peak('base', 20_000, 10 * 60, 11 * 60),
        ], CarbonImmutable::parse('2026-06-15 10:30:00', 'UTC'), 60);

        $this->assertSame(10_000, $snapshot['services_cents']);
        $this->assertSame(30, $snapshot['time_windows'][0]['minutes']);
    }

    public function test_a_booking_outside_every_window_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This service has no price for that date.');

        $this->price([
            $this->peak('base', 20_000, 10 * 60, 11 * 60),
        ], CarbonImmutable::parse('2026-06-15 12:00:00', 'UTC'), 60);
    }

    public function test_per_person_peak_minutes_are_multiplied_before_half_up_rounding(): void
    {
        $snapshot = $this->price([
            new PriceRuleInput('per_person', 5),
            $this->peak('per_person', 0, (10 * 60) + 1, 11 * 60),
        ], CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'), 2, 3);

        $this->assertSame(8, $snapshot['services_cents']);
    }

    public function test_a_base_peak_does_not_replace_a_per_person_rate(): void
    {
        $snapshot = $this->price([
            new PriceRuleInput('base', 5_000),
            new PriceRuleInput('per_person', 1_000),
            $this->peak('base', 8_000, 10 * 60, 12 * 60),
        ], CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'), 60, 3);

        $this->assertSame(11_000, $snapshot['services_cents']);
        $this->assertCount(1, $snapshot['time_windows']);
        $this->assertSame('base', $snapshot['time_windows'][0]['kind']);
    }

    public function test_a_duration_peak_prorates_only_the_matching_length(): void
    {
        $snapshot = $this->price([
            new PriceRuleInput('duration', 8_000, 'always', 60),
            new PriceRuleInput('duration', 30_000, 'always', 120),
            $this->peak('duration', 12_000, (10 * 60) + 30, (11 * 60) + 30, duration: 60),
        ], CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'), 60);

        $this->assertSame(10_000, $snapshot['services_cents']);
    }

    public function test_spring_forward_skips_the_missing_hour_and_fall_back_counts_the_repeated_hour(): void
    {
        $zone = 'America/New_York';
        $spring = $this->price([
            new PriceRuleInput('base', 6_000),
            $this->peak('base', 12_000, 1 * 60, 3 * 60),
        ], CarbonImmutable::parse('2026-03-08 01:30:00', $zone), 60);
        $missing = $this->price([
            new PriceRuleInput('base', 8_000),
            $this->peak('base', 20_000, 2 * 60, 3 * 60),
        ], CarbonImmutable::parse('2026-03-08 01:30:00', $zone), 60);
        $fall = $this->price([
            new PriceRuleInput('base', 6_000),
            $this->peak('base', 12_000, 1 * 60, 2 * 60),
        ], CarbonImmutable::parse('2026-11-01 00:30:00', $zone), 180);

        $this->assertSame(9_000, $spring['services_cents']);
        $this->assertSame(30, $spring['time_windows'][0]['minutes']);
        $this->assertSame(8_000, $missing['services_cents']);
        $this->assertSame([], $missing['time_windows']);
        $this->assertSame(10_000, $fall['services_cents']);
        $this->assertSame(120, $fall['time_windows'][0]['minutes']);
    }

    public function test_a_clock_window_needs_two_different_minutes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new PriceRuleInput('base', 100, 'always', null, null, null, 60, 60);
    }

    /**
     * @param  list<PriceRuleInput>  $rules
     * @return array<string, mixed>
     */
    private function price(array $rules, CarbonImmutable $on, int $minutes, int $participants = 1): array
    {
        return $this->engine->price(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            $rules,
            $participants,
            $minutes,
            $on,
            0,
            null,
            null,
            null,
            100,
        )->toSnapshot();
    }

    /**
     * @param  list<int>|null  $weekdays
     */
    private function peak(
        string $kind,
        int $cents,
        int $start,
        int $end,
        string $window = 'always',
        ?array $weekdays = null,
        ?string $startsOn = null,
        ?string $endsOn = null,
        ?int $duration = null,
    ): PriceRuleInput {
        return new PriceRuleInput($kind, $cents, $window, $duration, $startsOn, $endsOn, $start, $end, $weekdays);
    }
}
