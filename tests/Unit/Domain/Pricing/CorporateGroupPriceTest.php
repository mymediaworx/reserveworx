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

class CorporateGroupPriceTest extends TestCase
{
    private PricingEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new PricingEngine(new FeeCalculator);
    }

    public function test_a_percent_off_reduces_the_service_total_for_a_large_enough_party(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000, ruleId: 'base', name: 'Open'),
            new PriceRuleInput('base', 0, minParticipants: 8, percentOff: 10, ruleId: 'group', name: 'Group of 8'),
        ];
        $on = CarbonImmutable::parse('2026-06-15');

        $small = $this->price($rules, 2, $on);
        $large = $this->price($rules, 8, $on);

        $this->assertSame(10_000, $small['services_cents']);
        $this->assertSame([], $small['group_rules']);
        $this->assertSame(9_000, $large['services_cents']);
        $this->assertSame(9_270, $large['customer_total_cents']);
        $this->assertSame(10, $large['party_bands'][0]['percent_off']);
        $this->assertSame('group', $large['group_rules'][0]['price_rule_id']);
        $this->assertSame('Group of 8', $large['group_rules'][0]['name']);
    }

    public function test_a_corporate_rate_replaces_the_public_rate_of_the_same_kind(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('per_person', 2_000),
            new PriceRuleInput('per_person', 1_500, corporateAccountId: 'acct-north', corporateAccountName: 'Northwind', ruleId: 'corp', name: 'Northwind people'),
            new PriceRuleInput('per_person', 500, corporateAccountId: 'acct-other', corporateAccountName: 'Other'),
        ];
        $on = CarbonImmutable::parse('2026-06-15');

        $public = $this->price($rules, 4, $on);
        $corporate = $this->price($rules, 4, $on, corporateAccountId: 'acct-north');

        $this->assertSame(18_000, $public['services_cents']);
        $this->assertSame(16_000, $corporate['services_cents']);
        $this->assertSame('acct-north', $corporate['group_rules'][0]['corporate_account_id']);
        $this->assertSame('Northwind', $corporate['group_rules'][0]['corporate_account_name']);
    }

    public function test_a_corporate_party_band_wins_over_a_higher_public_minimum(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 3_000, minParticipants: 10),
            new PriceRuleInput('base', 7_000, minParticipants: 6, corporateAccountId: 'acct-north', ruleId: 'corp'),
        ];

        $priced = $this->price($rules, 12, CarbonImmutable::parse('2026-06-15'), corporateAccountId: 'acct-north');

        $this->assertSame(7_000, $priced['services_cents']);
        $this->assertSame('corp', $priced['group_rules'][0]['price_rule_id']);
    }

    public function test_a_party_below_the_corporate_tier_keeps_the_public_price(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 5_000, minParticipants: 8, corporateAccountId: 'acct-north'),
            new PriceRuleInput('base', 0, minParticipants: 4, percentOff: 10),
        ];
        $on = CarbonImmutable::parse('2026-06-15');

        $below = $this->price($rules, 4, $on, corporateAccountId: 'acct-north');
        $above = $this->price($rules, 8, $on, corporateAccountId: 'acct-north');

        $this->assertSame(9_000, $below['services_cents']);
        $this->assertSame(10, $below['group_rules'][0]['percent_off']);
        $this->assertSame(5_000, $above['services_cents']);
        $this->assertNull($above['group_rules'][0]['percent_off']);
    }

    public function test_a_corporate_percent_replaces_the_public_percent(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 0, minParticipants: 1, percentOff: 10),
            new PriceRuleInput('base', 0, minParticipants: 1, percentOff: 25, corporateAccountId: 'acct-north', ruleId: 'corp-off'),
        ];

        $priced = $this->price($rules, 3, CarbonImmutable::parse('2026-06-15'), corporateAccountId: 'acct-north');

        $this->assertSame(7_500, $priced['services_cents']);
        $this->assertSame('corp-off', $priced['group_rules'][0]['price_rule_id']);
    }

    public function test_peak_minutes_do_not_record_an_unused_corporate_rate(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('base', 6_000, corporateAccountId: 'acct-north', ruleId: 'corp'),
            new PriceRuleInput('base', 16_000, localStartMinute: 10 * 60, localEndMinute: 14 * 60),
            new PriceRuleInput('base', 0, minParticipants: 1, percentOff: 10, ruleId: 'public-off'),
        ];
        $inside = CarbonImmutable::parse('2026-06-15 10:00', 'America/New_York');

        $priced = $this->price($rules, 4, $inside, 60, 'acct-north');

        $this->assertSame(14_400, $priced['services_cents']);
        $this->assertSame('public-off', $priced['group_rules'][0]['price_rule_id']);
        $this->assertNull($priced['group_rules'][0]['corporate_account_id']);
    }

    public function test_an_increase_keeps_the_corporate_rate(): void
    {
        $rules = [
            new PriceRuleInput('base', 10_000),
            new PriceRuleInput('per_person', 2_000),
            new PriceRuleInput('per_person', 1_000, corporateAccountId: 'acct-north', ruleId: 'corp'),
        ];
        $on = CarbonImmutable::parse('2026-06-15');
        $increase = $this->engine->increaseFromQuote(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            $rules,
            8,
            60,
            $on,
            0,
            null,
            null,
            12_000,
            0,
            0,
            corporateAccountId: 'acct-north',
        );

        $this->assertSame(6_000, $increase->servicesDeltaCents);
        $this->assertSame('corp', $increase->partyBands[0]['price_rule_id']);
        $this->assertSame('acct-north', $increase->partyBands[0]['corporate_account_id']);
    }

    public function test_a_percent_off_needs_a_party_size_and_no_dollar_amount(): void
    {
        try {
            new PriceRuleInput('base', 0, percentOff: 10);
            $this->fail('A percent off without a party size was accepted.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('A percent off needs a party size.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('does not also name an amount');

        new PriceRuleInput('base', 1_000, minParticipants: 4, percentOff: 10);
    }

    /**
     * @param  list<PriceRuleInput>  $rules
     * @return array<string, mixed>
     */
    private function price(array $rules, int $participants, CarbonImmutable $on, int $durationMinutes = 60, ?string $corporateAccountId = null): array
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
            corporateAccountId: $corporateAccountId,
        )->toSnapshot();
    }
}
