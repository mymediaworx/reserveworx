<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Fees\FeeBasis;
use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Pricing\PriceRuleInput;
use App\Domain\Pricing\PricingEngine;
use App\Domain\Pricing\VariantPrice;
use App\Enums\FeePayer;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class VariantPriceTest extends TestCase
{
    public function test_an_override_replaces_the_open_base_and_keeps_the_peak_window(): void
    {
        $rules = VariantPrice::overrideBase([
            new PriceRuleInput('base', 4_000),
            new PriceRuleInput('base', 16_000, localStartMinute: 600, localEndMinute: 840),
            new PriceRuleInput('per_person', 500),
        ], 8_000);

        $this->assertSame('base', $rules[0]->kind);
        $this->assertSame(8_000, $rules[0]->amountCents);
        $this->assertFalse($rules[0]->hasTimeWindow());
        $this->assertTrue($rules[1]->hasTimeWindow());
        $this->assertSame('per_person', $rules[2]->kind);

        $engine = new PricingEngine(new FeeCalculator);
        $offPeak = $engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            $rules,
            1,
            60,
            CarbonImmutable::parse('2026-06-15 08:00', 'America/New_York'),
            0,
            null,
            null,
            null,
            100,
        );
        $peak = $engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            $rules,
            1,
            60,
            CarbonImmutable::parse('2026-06-15 10:00', 'America/New_York'),
            0,
            null,
            null,
            null,
            100,
        );

        $this->assertSame(8_500, $offPeak->toSnapshot()['services_cents']);
        $this->assertSame(16_500, $peak->toSnapshot()['services_cents']);
    }

    public function test_a_mandatory_operator_fee_is_added_to_the_variant_service_total(): void
    {
        $basis = new FeeBasis(8_000, mandatoryOperatorFeesCents: 500);

        $this->assertSame(8_500, $basis->cents());
        $this->assertSame(255, (new FeeCalculator)->feeFor(new FeeRate(300), $basis->cents()));
    }

    public function test_a_missing_override_leaves_the_service_rates_alone(): void
    {
        $rules = [
            new PriceRuleInput('base', 4_000),
        ];

        $this->assertSame($rules, VariantPrice::overrideBase($rules, null));
    }
}
