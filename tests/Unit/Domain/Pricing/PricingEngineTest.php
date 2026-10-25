<?php

namespace Tests\Unit\Domain\Pricing;

use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Domain\Fees\PaymentKind;
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
        $this->assertSame(0, $snapshot['add_ons_cents']);
        $this->assertSame([], $snapshot['add_ons']);
        $this->assertSame(0, $snapshot['mandatory_operator_fees_cents']);
        $this->assertSame([], $snapshot['operator_fees']);
    }

    public function test_add_ons_join_the_fee_basis_and_the_snapshot_without_changing_service_tax(): void
    {
        $lines = [
            ['name' => 'Cooler', 'total_cents' => 1_500],
            ['name' => 'Paddle', 'total_cents' => 1_400],
        ];
        $priced = $this->engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000, 'weekday')],
            2,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            10,
            null,
            'SAVE10',
            100,
            2_900,
            $lines,
        );
        $snapshot = $priced->toSnapshot();

        $this->assertSame(10_000, $snapshot['services_cents']);
        $this->assertSame(1_000, $snapshot['discount_cents']);
        $this->assertSame(2_900, $snapshot['add_ons_cents']);
        $this->assertSame($lines, $snapshot['add_ons']);
        $this->assertSame(11_900, $snapshot['basis_cents']);
        $this->assertSame(900, $snapshot['tax_cents']);
        $this->assertSame(357, $snapshot['fee_cents']);
        $this->assertSame(357, $snapshot['customer_fee_line_cents']);
        $this->assertSame(13_157, $snapshot['customer_total_cents']);
        $this->assertSame(13_157, $snapshot['charge_cents']);
        $this->assertSame(357, $snapshot['application_fee_cents']);
        $this->assertFalse($snapshot['tax_includes_booking_fee']);
        $this->assertSame(0, $snapshot['mandatory_operator_fees_cents']);
    }

    public function test_mandatory_fees_join_the_basis_and_a_promo_does_not_reduce_them(): void
    {
        $priced = $this->engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000, 'weekday')],
            2,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            10,
            null,
            'SAVE10',
            100,
            1_500,
            [['name' => 'Cooler', 'total_cents' => 1_500]],
            [
                [
                    'operator_fee_id' => '01FUEL',
                    'name' => 'Fuel',
                    'pricing' => 'percent',
                    'scope' => 'location',
                    'amount_cents' => null,
                    'percent_bps' => 1_000,
                ],
                [
                    'operator_fee_id' => '01CLEAN',
                    'name' => 'Cleaning',
                    'pricing' => 'flat',
                    'scope' => 'service',
                    'amount_cents' => 300,
                    'percent_bps' => null,
                ],
            ],
        );
        $snapshot = $priced->toSnapshot();

        $this->assertSame(10_000, $snapshot['services_cents']);
        $this->assertSame(1_000, $snapshot['discount_cents']);
        $this->assertSame(1_500, $snapshot['add_ons_cents']);
        $this->assertSame(1_300, $snapshot['mandatory_operator_fees_cents']);
        $this->assertSame(1_000, $snapshot['operator_fees'][0]['total_cents']);
        $this->assertSame('Fuel', $snapshot['operator_fees'][0]['name']);
        $this->assertSame(300, $snapshot['operator_fees'][1]['total_cents']);
        $this->assertSame(11_800, $snapshot['basis_cents']);
        $this->assertSame(900, $snapshot['tax_cents']);
        $this->assertSame(354, $snapshot['fee_cents']);
        $this->assertSame(13_054, $snapshot['customer_total_cents']);
        $this->assertFalse($snapshot['tax_includes_booking_fee']);
    }

    public function test_a_party_size_increase_reprices_per_person_and_percent_fees(): void
    {
        $definitions = [
            [
                'operator_fee_id' => '01CLEAN',
                'name' => 'Cleaning',
                'pricing' => 'per_person',
                'scope' => 'service',
                'amount_cents' => 500,
                'percent_bps' => null,
            ],
            [
                'operator_fee_id' => '01FUEL',
                'name' => 'Fuel',
                'pricing' => 'percent',
                'scope' => 'location',
                'amount_cents' => null,
                'percent_bps' => 1_000,
            ],
            [
                'operator_fee_id' => '01ADMIN',
                'name' => 'Admin',
                'pricing' => 'flat',
                'scope' => 'service',
                'amount_cents' => 300,
                'percent_bps' => null,
            ],
        ];
        $increase = $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('per_person', 5_000, 'weekday')],
            4,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            10,
            null,
            10_000,
            1_000,
            900,
            400,
            800,
            2_300,
            $definitions,
        );

        $this->assertSame(10_000, $increase->servicesDeltaCents);
        $this->assertSame(1_000, $increase->discountDeltaCents);
        $this->assertSame(400, $increase->addOnsDeltaCents);
        $this->assertSame(2_000, $increase->operatorFeesDeltaCents);
        $this->assertSame(11_400, $increase->basisCents);
        $this->assertSame(900, $increase->taxCents);
        $this->assertSame(342, $increase->payment->applicationFeeCents);
        $this->assertSame(12_642, $increase->payment->chargeCents);
        $this->assertSame(2_000, $increase->operatorFeeLines[0]['total_cents']);
        $this->assertSame(2_000, $increase->operatorFeeLines[1]['total_cents']);
        $this->assertSame(300, $increase->operatorFeeLines[2]['total_cents']);
    }

    public function test_a_flat_fee_alone_does_not_invent_a_party_size_increase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This booking has no price increase.');

        $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000, 'weekday')],
            4,
            60,
            CarbonImmutable::parse('2026-06-15'),
            0,
            null,
            null,
            10_000,
            0,
            0,
            0,
            0,
            300,
            [[
                'operator_fee_id' => '01ADMIN',
                'name' => 'Admin',
                'pricing' => 'flat',
                'scope' => 'service',
                'amount_cents' => 300,
                'percent_bps' => null,
            ]],
        );
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

    public function test_increase_uses_the_percentage_on_the_difference_only(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000, 'weekday'),
            new PriceRuleInput('base', 20_000, 'weekend'),
        ];
        $increase = $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            new FeeRate(300, 50, 500),
            FeePayer::CustomerPaid,
            $rules,
            2,
            60,
            CarbonImmutable::parse('2026-06-20'),
            0,
            10,
            null,
            10_000,
            1_000,
            0,
        );

        $this->assertSame(10_000, $increase->servicesDeltaCents);
        $this->assertSame(1_000, $increase->discountDeltaCents);
        $this->assertSame(9_000, $increase->basisCents);
        $this->assertSame(0, $increase->taxCents);
        $this->assertSame(0, $increase->addOnsDeltaCents);
        $this->assertSame(270, $increase->payment->applicationFeeCents);
        $this->assertSame(9_270, $increase->payment->chargeCents);
        $this->assertSame(PaymentKind::PriceIncreaseStripePayment, $increase->payment->kind);
        $this->assertSame(0, $increase->quote->rate->fixedCents);
        $this->assertNull($increase->quote->rate->minimumCents);
    }

    public function test_an_add_on_change_is_part_of_the_fee_basis(): void
    {
        $rules = [new PriceRuleInput('base', 10_000)];
        $on = CarbonImmutable::parse('2026-06-15');
        $increase = $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            $rules,
            2,
            60,
            $on,
            0,
            null,
            null,
            10_000,
            0,
            0,
            2_000,
            4_000,
        );

        $this->assertSame(0, $increase->servicesDeltaCents);
        $this->assertSame(2_000, $increase->addOnsDeltaCents);
        $this->assertSame(2_000, $increase->basisCents);
        $this->assertSame(0, $increase->taxCents);
        $this->assertSame(60, $increase->payment->applicationFeeCents);
        $this->assertSame(2_060, $increase->payment->chargeCents);

        $withService = $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            new FeeRate(300),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('per_person', 10_000)],
            2,
            60,
            $on,
            0,
            null,
            null,
            10_000,
            0,
            0,
            2_500,
            4_500,
        );

        $this->assertSame(10_000, $withService->servicesDeltaCents);
        $this->assertSame(2_000, $withService->addOnsDeltaCents);
        $this->assertSame(12_000, $withService->basisCents);
        $this->assertSame(360, $withService->payment->applicationFeeCents);

        $decrease = $this->engine->decreaseFromQuote(
            [new PriceRuleInput('per_person', 10_000)],
            1,
            60,
            $on,
            0,
            null,
            null,
            20_000,
            0,
            0,
            4_500,
            2_500,
        );

        $this->assertSame(10_000, $decrease->servicesCents);
        $this->assertSame(2_500, $decrease->addOnsCents);
        $this->assertSame(12_000, $decrease->basisReductionCents);
        $this->assertSame(0, $decrease->taxReductionCents);
    }

    public function test_a_lower_price_returns_the_subtotal_and_tax_reduction(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000, 'weekday'),
            new PriceRuleInput('base', 20_000, 'weekend'),
        ];
        $decrease = $this->engine->decreaseFromQuote(
            $rules,
            2,
            60,
            CarbonImmutable::parse('2026-06-15'),
            800,
            10,
            null,
            20_000,
            2_000,
            1_440,
        );

        $this->assertSame(10_000, $decrease->servicesCents);
        $this->assertSame(1_000, $decrease->discountCents);
        $this->assertSame(9_000, $decrease->basisReductionCents);
        $this->assertSame(720, $decrease->taxReductionCents);
    }

    public function test_a_price_that_did_not_fall_is_not_a_decrease(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This booking has no price decrease.');

        $this->engine->decreaseFromQuote(
            [new PriceRuleInput('base', 10_000, 'weekday')],
            2,
            60,
            CarbonImmutable::parse('2026-06-15'),
            0,
            null,
            null,
            10_000,
            0,
            0,
        );
    }

    public function test_a_price_that_did_not_rise_is_not_an_increase(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('This booking has no price increase.');

        $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            new FeeRate(300, 50, 500),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000, 'weekday')],
            2,
            60,
            CarbonImmutable::parse('2026-06-15'),
            0,
            null,
            null,
            10_000,
            0,
            0,
        );
    }

    public function test_location_rates_replace_the_single_rate_and_leave_the_booking_fee_untaxed(): void
    {
        $rules = [
            [
                'id' => '01STATE',
                'name' => 'State sales tax',
                'percent_bps' => 825,
                'applies_to_rentals' => true,
                'applies_to_add_ons' => true,
                'applies_to_fees' => true,
            ],
            [
                'id' => '01CITY',
                'name' => 'City tax',
                'percent_bps' => 100,
                'applies_to_rentals' => true,
                'applies_to_add_ons' => false,
                'applies_to_fees' => false,
            ],
        ];
        $fees = [[
            'operator_fee_id' => '01CLEAN',
            'name' => 'Cleaning',
            'pricing' => 'flat',
            'scope' => 'service',
            'amount_cents' => 500,
            'percent_bps' => null,
        ]];
        $priced = $this->engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000, 'weekday')],
            1,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            null,
            null,
            null,
            100,
            2_000,
            [['name' => 'Cooler', 'total_cents' => 2_000]],
            $fees,
            $rules,
        );
        $snapshot = $priced->toSnapshot();

        $this->assertSame(1_131, $snapshot['tax_cents']);
        $this->assertSame(375, $snapshot['fee_cents']);
        $this->assertSame(14_006, $snapshot['customer_total_cents']);
        $this->assertFalse($snapshot['tax_includes_booking_fee']);
        $this->assertSame('State sales tax', $snapshot['tax_lines'][0]['name']);
        $this->assertSame(1_031, $snapshot['tax_lines'][0]['cents']);
        $this->assertSame(12_500, $snapshot['tax_lines'][0]['basis_cents']);
        $this->assertSame('City tax', $snapshot['tax_lines'][1]['name']);
        $this->assertSame(100, $snapshot['tax_lines'][1]['cents']);
        $this->assertSame(10_000, $snapshot['tax_lines'][1]['basis_cents']);
        $this->assertArrayNotHasKey('tax_lines', $this->engine->price(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 10_000, 'weekday')],
            1,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            null,
            null,
            null,
            100,
        )->toSnapshot());

        $increase = $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            [new PriceRuleInput('base', 20_000, 'weekday')],
            1,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            null,
            null,
            10_000,
            0,
            1_131,
            2_000,
            2_000,
            500,
            $fees,
            $rules,
        );

        $this->assertSame(10_000, $increase->basisCents);
        $this->assertSame(925, $increase->taxCents);
        $this->assertSame(300, $increase->quote->toSnapshot()['fee_cents']);
        $this->assertIsArray($increase->taxLines);
        $this->assertSame(1_856, $increase->taxLines[0]['cents']);
        $this->assertSame(200, $increase->taxLines[1]['cents']);

        $decrease = $this->engine->decreaseFromQuote(
            [new PriceRuleInput('base', 10_000, 'weekday')],
            1,
            60,
            CarbonImmutable::parse('2026-06-15'),
            1_000,
            null,
            null,
            20_000,
            0,
            2_056,
            2_000,
            2_000,
            500,
            $fees,
            $rules,
        );

        $this->assertSame(10_000, $decrease->basisReductionCents);
        $this->assertSame(925, $decrease->taxReductionCents);
        $this->assertIsArray($decrease->taxLines);
        $this->assertSame(1_031, $decrease->taxLines[0]['cents']);
        $this->assertSame(100, $decrease->taxLines[1]['cents']);
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
