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

class GroupPriceTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PricingEngine(new FeeCalculator);
    }

    public function test_a_party_size_replaces_the_open_rate_of_the_same_kind(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 7_000, minParticipants: 8),
            new PriceRuleInput('per_person', 2_000),
            new PriceRuleInput('per_person', 1_500, minParticipants: 8, maxParticipants: 12),
        ];
        $on = CarbonImmutable::parse('2026-06-15');

        $small = $this->price($rules, 2, $on);
        $large = $this->price($rules, 8, $on);

        $this->assertSame(14_000, $small['services_cents']);
        $this->assertSame([], $small['party_bands']);
        $this->assertSame(19_000, $large['services_cents']);
        $this->assertSame(8, $large['party_bands'][0]['min_participants']);
        $this->assertNull($large['party_bands'][0]['max_participants']);
        $this->assertSame('base', $large['party_bands'][0]['kind']);
        $this->assertSame(7_000, $large['party_bands'][0]['amount_cents']);
        $this->assertSame(1_500, $large['party_bands'][1]['amount_cents']);
        $this->assertSame(12, $large['party_bands'][1]['max_participants']);
    }

    public function test_a_higher_minimum_beats_a_wider_range_and_a_narrower_maximum_beats_the_same_minimum(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 8_000, minParticipants: 6, maxParticipants: 20),
            new PriceRuleInput('base', 5_000, minParticipants: 6, maxParticipants: 10),
            new PriceRuleInput('base', 3_000, minParticipants: 9),
        ];
        $on = CarbonImmutable::parse('2026-06-15');

        $this->assertSame(5_000, $this->price($rules, 8, $on)['services_cents']);
        $this->assertSame(3_000, $this->price($rules, 12, $on)['services_cents']);
        $this->assertSame(10_000, $this->price($rules, 4, $on)['services_cents']);
    }

    public function test_the_earlier_rule_wins_when_two_party_sizes_are_the_same(): void
    {
        $rules = [
            new PriceRuleInput('base', 5_000, minParticipants: 6, maxParticipants: 10),
            new PriceRuleInput('base', 9_000, minParticipants: 6, maxParticipants: 10),
        ];

        $priced = $this->price($rules, 7, CarbonImmutable::parse('2026-06-15'));

        $this->assertSame(5_000, $priced['services_cents']);
        $this->assertSame(5_000, $priced['party_bands'][0]['amount_cents']);
    }

    public function test_a_weekend_party_size_does_not_apply_on_a_weekday(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 4_000, 'weekend', minParticipants: 4),
        ];

        $this->assertSame(10_000, $this->price($rules, 6, CarbonImmutable::parse('2026-06-15'))['services_cents']);
        $this->assertSame(4_000, $this->price($rules, 6, CarbonImmutable::parse('2026-06-20'))['services_cents']);
    }

    public function test_a_party_size_replaces_only_the_matching_duration(): void
    {
        $rules = [
            new PriceRuleInput('duration', 8_000, durationMinutes: 60),
            new PriceRuleInput('duration', 5_000, durationMinutes: 60, minParticipants: 4, maxParticipants: 6),
        ];
        $on = CarbonImmutable::parse('2026-06-15');

        $this->assertSame(5_000, $this->engine->serviceCents($rules, 5, 60, $on));
        $this->assertSame(8_000, $this->engine->serviceCents($rules, 2, 60, $on));
    }

    public function test_peak_minutes_keep_the_peak_rate_and_the_party_size_prices_the_rest(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 6_000, minParticipants: 6),
            new PriceRuleInput('base', 16_000, localStartMinute: 10 * 60, localEndMinute: 14 * 60),
        ];
        $zone = 'America/New_York';
        $crossed = CarbonImmutable::parse('2026-06-15 13:30', $zone);
        $inside = CarbonImmutable::parse('2026-06-15 10:00', $zone);

        $group = $this->price($rules, 8, $crossed, 60);
        $open = $this->price($rules, 2, $crossed, 60);
        $peak = $this->price($rules, 8, $inside, 60);

        $this->assertSame(11_000, $group['services_cents']);
        $this->assertSame(6_000, $group['party_bands'][0]['amount_cents']);
        $this->assertSame(13_000, $open['services_cents']);
        $this->assertSame([], $open['party_bands']);
        $this->assertSame(16_000, $peak['services_cents']);
        $this->assertSame([], $peak['party_bands']);
    }

    public function test_a_party_size_cannot_also_name_a_clock_window(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('clock window');

        new PriceRuleInput('base', 1_000, localStartMinute: 600, localEndMinute: 720, minParticipants: 4);
    }

    public function test_a_party_size_maximum_needs_a_minimum_and_must_cover_it(): void
    {
        try {
            new PriceRuleInput('base', 1_000, maxParticipants: 8);
            $this->fail('A maximum without a minimum was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A party size needs a minimum.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least the minimum');

        new PriceRuleInput('base', 1_000, minParticipants: 6, maxParticipants: 4);
    }

    /**
     * @param  list<PriceRuleInput>  $rules
     * @return array<string, mixed>
     */
    private function price(array $rules, int $participants, CarbonImmutable $on, int $durationMinutes = 60): array
    {
        return $this->engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            $rules,
            $participants,
            $durationMinutes,
            $on,
            0,
            null,
            null,
            null,
            100,
        )->toSnapshot();
    }
}
