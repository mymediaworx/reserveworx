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

class PricingEngineTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PricingEngine(new FeeCalculator);
    }

    public function test_a_weekday_rate_excludes_the_weekend_rate_and_keeps_tax_out_of_the_fee(): void
    {
        $priced = $this->engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            [
                new PriceRuleInput('base', 10_000, 'weekday'),
                new PriceRuleInput('base', 20_000, 'weekend'),
            ],
            2,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            10,
            null,
            'SAVE10',
            100,
        );

        $snapshot = $priced->toSnapshot();

        $this->assertSame(10_000, $snapshot['services_cents']);
        $this->assertSame(1_000, $snapshot['discount_cents']);
        $this->assertSame(900, $snapshot['tax_cents']);
        $this->assertSame(270, $snapshot['fee_cents']);
        $this->assertSame(270, $snapshot['customer_fee_line_cents']);
        $this->assertSame(10_170, $snapshot['customer_total_cents']);
        $this->assertSame(10_170, $snapshot['charge_cents']);
        $this->assertSame(270, $snapshot['application_fee_cents']);
        $this->assertFalse($snapshot['tax_includes_booking_fee']);
        $this->assertFalse($snapshot['deposit_was_raised']);
        $this->assertSame('SAVE10', $snapshot['promo_code']);
    }

    public function test_a_weekend_rate_and_a_per_person_rate_add_together(): void
    {
        $priced = $this->engine->price(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            [
                new PriceRuleInput('base', 5_000, 'weekend'),
                new PriceRuleInput('per_person', 1_000, 'always'),
            ],
            3,
            60,
            CarbonImmutable::parse('2026-06-20'),
            0,
            null,
            null,
            null,
            100,
        );

        $this->assertSame(8_000, $priced->servicesCents);
        $this->assertSame(240, $priced->toSnapshot()['fee_cents']);
        $this->assertSame(8_240, $priced->toSnapshot()['customer_total_cents']);
    }

    public function test_a_deposit_still_carries_the_full_booking_fee(): void
    {
        $priced = $this->engine->price(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000)],
            1,
            60,
            CarbonImmutable::parse('2026-06-15'),
            0,
            null,
            null,
            null,
            50,
        );
        $snapshot = $priced->toSnapshot();

        $this->assertSame(5_150, $snapshot['charge_cents']);
        $this->assertSame(300, $snapshot['application_fee_cents']);
        $this->assertSame(10_300, $snapshot['customer_total_cents']);
        $this->assertFalse($snapshot['deposit_was_raised']);
    }

    public function test_a_service_with_no_matching_rule_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This service has no price for that date.');

        $this->engine->price(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000, 'weekend')],
            1,
            60,
            CarbonImmutable::parse('2026-06-15'),
            0,
            null,
            null,
            null,
            100,
        );
    }
}
