<?php

namespace Tests\Unit\Domain\Fees;

use App\Domain\Fees\BookingFeeQuote;
use App\Domain\Fees\FeeBasis;
use App\Domain\Fees\FeeCalculator;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeExceedsChargeException;
use App\Domain\Fees\FeeRate;
use App\Domain\Fees\PaymentKind;
use App\Enums\FeePayer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FeeCalculatorTest extends TestCase
{
    private FeeCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new FeeCalculator;
    }

    private function quote(
        int $basis,
        int $tax = 0,
        FeePayer $payer = FeePayer::CustomerPaid,
        ?FeeRate $rate = null,
        FeeChannel $channel = FeeChannel::Direct,
    ): BookingFeeQuote {
        return $this->calculator->quote(
            $channel,
            $rate ?? FeeRate::defaultFor($channel),
            $payer,
            FeeBasis::ofSubtotal($basis),
            $tax,
        );
    }

    // ---------------------------------------------------------------
    // Rates per channel, fixed amount, minimum (Section 7.4)
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{FeeChannel, int, int}>
     */
    public static function channelDefaults(): array
    {
        return [
            'direct 3% of $100' => [FeeChannel::Direct, 10_000, 300],
            'third-party 1% of $100' => [FeeChannel::ThirdParty, 10_000, 100],
            'direct 3% of $33.33 rounds 99.99 up' => [FeeChannel::Direct, 3_333, 100],
            'third-party 1% of $33.33 rounds 33.33 down' => [FeeChannel::ThirdParty, 3_333, 33],
            'direct 3% of $10.50 is exactly 31.5 and rounds up' => [FeeChannel::Direct, 1_050, 32],
            'third-party 1% of $0.50 is exactly 0.5 and rounds up' => [FeeChannel::ThirdParty, 50, 1],
            'third-party 1% of $0.49 rounds to zero' => [FeeChannel::ThirdParty, 49, 0],
        ];
    }

    #[DataProvider('channelDefaults')]
    public function test_default_channel_rates(FeeChannel $channel, int $basis, int $expectedFee): void
    {
        $this->assertSame($expectedFee, $this->calculator->feeFor(FeeRate::defaultFor($channel), $basis));
    }

    /**
     * @return array<string, array{FeeRate, int, int}>
     */
    public static function optionalComponents(): array
    {
        return [
            'fixed amount is added to the percentage' => [new FeeRate(300, 30), 10_000, 330],
            'minimum raises a small fee' => [new FeeRate(300, 0, 100), 1_000, 100],
            'minimum below the percentage is ignored' => [new FeeRate(300, 0, 100), 10_000, 300],
            'minimum applies after the fixed amount' => [new FeeRate(300, 30, 50), 1_000, 60],
            'minimum above percentage plus fixed wins' => [new FeeRate(300, 30, 75), 1_000, 75],
            'zero minimum is enabled but has no effect' => [new FeeRate(300, 0, 0), 1_000, 30],
            'fixed only' => [new FeeRate(0, 50), 1_000, 50],
            'everything disabled' => [new FeeRate(0), 10_000, 0],
        ];
    }

    #[DataProvider('optionalComponents')]
    public function test_fixed_amount_and_minimum(FeeRate $rate, int $basis, int $expectedFee): void
    {
        $this->assertSame($expectedFee, $this->calculator->feeFor($rate, $basis));
    }

    public function test_zero_basis_carries_no_fee_even_with_fixed_and_minimum(): void
    {
        $this->assertSame(0, $this->calculator->feeFor(new FeeRate(300, 50, 100), 0));
    }

    public function test_negative_basis_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->feeFor(FeeRate::defaultFor(FeeChannel::Direct), -1);
    }

    // ---------------------------------------------------------------
    // Fee basis excludes taxes and the fee itself
    // ---------------------------------------------------------------

    public function test_basis_includes_add_ons_and_mandatory_fees_and_subtracts_discounts(): void
    {
        $quote = $this->calculator->quote(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            new FeeBasis(servicesCents: 10_000, addOnsCents: 2_000, mandatoryOperatorFeesCents: 500, discountsCents: 1_500),
            taxCents: 800,
        );

        $this->assertSame(11_000, $quote->basisCents);
        $this->assertSame(330, $quote->feeCents);
    }

    public function test_tax_does_not_change_the_fee(): void
    {
        $this->assertSame($this->quote(10_000, 0)->feeCents, $this->quote(10_000, 2_500)->feeCents);
    }

    public function test_fee_is_not_charged_on_itself(): void
    {
        // Customer total includes the fee, but the fee is still 3% of the subtotal only.
        $quote = $this->quote(10_000, 700);
        $this->assertSame(300, $quote->feeCents);
        $this->assertSame(11_000, $quote->customerTotalCents());
    }

    public function test_negative_tax_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->quote(10_000, -1);
    }

    // ---------------------------------------------------------------
    // Fee payer modes (Section 7.5)
    // ---------------------------------------------------------------

    public function test_customer_paid_adds_a_booking_fee_line_to_the_total(): void
    {
        $quote = $this->quote(10_000, 700, FeePayer::CustomerPaid);

        $this->assertSame(300, $quote->customerFeeLineCents());
        $this->assertSame(10_000 + 700 + 300, $quote->customerTotalCents());

        $payment = $this->calculator->firstStripePayment($quote, $quote->customerTotalCents());
        $this->assertSame(11_000, $payment->chargeCents);
        $this->assertSame(300, $payment->stripeApplicationFeeAmount());
    }

    public function test_operator_absorbed_shows_no_fee_line_and_deducts_the_same_fee(): void
    {
        $quote = $this->quote(10_000, 700, FeePayer::OperatorAbsorbed);

        $this->assertNull($quote->customerFeeLineCents());
        $this->assertSame(10_700, $quote->customerTotalCents());

        $payment = $this->calculator->firstStripePayment($quote, $quote->customerTotalCents());
        $this->assertSame(10_700, $payment->chargeCents);
        $this->assertSame(300, $payment->stripeApplicationFeeAmount());
    }

    public function test_both_modes_charge_the_same_fee(): void
    {
        $this->assertSame(
            $this->quote(12_345, 999, FeePayer::CustomerPaid)->feeCents,
            $this->quote(12_345, 999, FeePayer::OperatorAbsorbed)->feeCents,
        );
    }

    public function test_snapshot_records_rate_payer_and_amounts(): void
    {
        $snapshot = $this->quote(10_000, 700, FeePayer::OperatorAbsorbed, new FeeRate(250, 10, 50))->toSnapshot();

        $this->assertSame([
            'rules_version' => FeeCalculator::RULES_VERSION,
            'channel' => 'direct',
            'rate' => ['basis_points' => 250, 'fixed_cents' => 10, 'minimum_cents' => 50],
            'fee_payer' => 'operator_absorbed',
            'basis_cents' => 10_000,
            'tax_cents' => 700,
            'fee_cents' => 260,
            'customer_fee_line_cents' => null,
            'customer_total_cents' => 10_700,
        ], $snapshot);
    }

    // ---------------------------------------------------------------
    // Full fee on the first payment, deposits included
    // ---------------------------------------------------------------

    public function test_full_fee_is_collected_on_a_deposit(): void
    {
        $payment = $this->calculator->firstStripePayment($this->quote(10_000), 2_000);

        $this->assertSame(PaymentKind::FirstStripePayment, $payment->kind);
        $this->assertSame(2_000, $payment->chargeCents);
        $this->assertSame(300, $payment->applicationFeeCents);
        $this->assertFalse($payment->depositWasRaised());
    }

    public function test_later_balance_payment_carries_no_fee(): void
    {
        $payment = $this->calculator->balanceStripePayment(8_300);

        $this->assertSame(PaymentKind::BalanceStripePayment, $payment->kind);
        $this->assertSame(8_300, $payment->chargeCents);
        $this->assertSame(0, $payment->applicationFeeCents);
        $this->assertNull($payment->stripeApplicationFeeAmount());
    }

    public function test_deposit_then_balance_collects_the_fee_exactly_once(): void
    {
        $quote = $this->quote(10_000, 0, FeePayer::CustomerPaid);
        $deposit = $this->calculator->firstStripePayment($quote, 2_500);
        $balance = $this->calculator->balanceStripePayment($quote->customerTotalCents() - $deposit->chargeCents);

        $this->assertSame($quote->customerTotalCents(), $deposit->chargeCents + $balance->chargeCents);
        $this->assertSame($quote->feeCents, $deposit->applicationFeeCents + $balance->applicationFeeCents);
    }

    // ---------------------------------------------------------------
    // Deposit floor: fee + $1.00
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{int, int, bool}>
     */
    public static function depositFloorCases(): array
    {
        // $100 booking at 3%: fee 300.
        return [
            'deposit below the fee is raised' => [100, 400, true],
            'deposit equal to the fee is raised' => [300, 400, true],
            'deposit one cent above the fee is kept' => [301, 301, false],
            'deposit at fee + $1 is kept' => [400, 400, false],
            'normal deposit is kept' => [2_500, 2_500, false],
        ];
    }

    #[DataProvider('depositFloorCases')]
    public function test_deposit_floor(int $requested, int $expectedCharge, bool $raised): void
    {
        $payment = $this->calculator->firstStripePayment($this->quote(10_000), $requested);

        $this->assertSame($expectedCharge, $payment->chargeCents);
        $this->assertSame($requested, $payment->requestedCents);
        $this->assertSame($raised, $payment->depositWasRaised());
        $this->assertSame(300, $payment->applicationFeeCents);
        $this->assertGreaterThan($payment->applicationFeeCents, $payment->chargeCents);
    }

    public function test_raised_deposit_is_capped_at_the_customer_total(): void
    {
        // $0.50 subtotal, minimum fee $0.40, customer-paid: total $0.90, fee + $1 would be $1.40.
        $quote = $this->quote(50, 0, FeePayer::CustomerPaid, new FeeRate(300, 0, 40));
        $payment = $this->calculator->firstStripePayment($quote, 30);

        $this->assertSame(90, $payment->chargeCents);
        $this->assertSame(40, $payment->applicationFeeCents);
        $this->assertTrue($payment->depositWasRaised());
    }

    public function test_operator_absorbed_deposit_floor_also_applies(): void
    {
        $quote = $this->quote(10_000, 0, FeePayer::OperatorAbsorbed);
        $payment = $this->calculator->firstStripePayment($quote, 200);

        $this->assertSame(400, $payment->chargeCents);
        $this->assertSame(300, $payment->applicationFeeCents);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function absorbedFeeNotBelowTotal(): array
    {
        return [
            'fee greater than total' => [60],
            'fee equal to total' => [50],
        ];
    }

    #[DataProvider('absorbedFeeNotBelowTotal')]
    public function test_operator_absorbed_checkout_is_refused_when_fee_is_not_below_total(int $minimum): void
    {
        $quote = $this->quote(50, 0, FeePayer::OperatorAbsorbed, new FeeRate(300, 0, $minimum));

        $this->expectException(FeeExceedsChargeException::class);
        $this->calculator->firstStripePayment($quote, 50);
    }

    public function test_customer_paid_charge_always_exceeds_the_fee(): void
    {
        foreach ([1, 7, 50, 99, 100, 101, 999] as $basis) {
            $quote = $this->quote($basis, 0, FeePayer::CustomerPaid, new FeeRate(10_000, 100, 500));
            $payment = $this->calculator->firstStripePayment($quote, 1);

            $this->assertGreaterThan($payment->applicationFeeCents, $payment->chargeCents, "basis {$basis}");
        }
    }

    // ---------------------------------------------------------------
    // Zero fee: omit application_fee_amount
    // ---------------------------------------------------------------

    public function test_zero_fee_is_omitted_and_deposit_is_not_raised(): void
    {
        $quote = $this->quote(10_000, 0, FeePayer::CustomerPaid, new FeeRate(0));
        $payment = $this->calculator->firstStripePayment($quote, 100);

        $this->assertSame(100, $payment->chargeCents);
        $this->assertNull($payment->stripeApplicationFeeAmount());
        $this->assertFalse($payment->depositWasRaised());
    }

    public function test_booking_with_only_tax_has_no_fee(): void
    {
        $quote = $this->quote(0, 200);
        $payment = $this->calculator->firstStripePayment($quote, 200);

        $this->assertSame(0, $quote->feeCents);
        $this->assertNull($payment->stripeApplicationFeeAmount());
    }

    // ---------------------------------------------------------------
    // Price increases
    // ---------------------------------------------------------------

    public function test_increase_is_charged_the_fee_on_the_increase_only(): void
    {
        $increase = $this->calculator->quoteIncrease(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            FeeBasis::ofSubtotal(5_000),
            350,
        );
        $payment = $this->calculator->priceIncreaseStripePayment($increase);

        $this->assertSame(150, $increase->feeCents);
        $this->assertSame(PaymentKind::PriceIncreaseStripePayment, $payment->kind);
        $this->assertSame(5_000 + 350 + 150, $payment->chargeCents);
        $this->assertSame(150, $payment->applicationFeeCents);
    }

    public function test_operator_absorbed_increase_has_no_fee_line(): void
    {
        $increase = $this->calculator->quoteIncrease(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::OperatorAbsorbed,
            FeeBasis::ofSubtotal(5_000),
            350,
        );
        $payment = $this->calculator->priceIncreaseStripePayment($increase);

        $this->assertNull($increase->customerFeeLineCents());
        $this->assertSame(5_350, $payment->chargeCents);
        $this->assertSame(150, $payment->applicationFeeCents);
    }

    public function test_third_party_increase_uses_the_third_party_rate(): void
    {
        $increase = $this->calculator->quoteIncrease(
            FeeChannel::ThirdParty,
            FeeRate::defaultFor(FeeChannel::ThirdParty),
            FeePayer::CustomerPaid,
            FeeBasis::ofSubtotal(5_000),
        );

        $this->assertSame(50, $increase->feeCents);
    }

    public function test_increase_does_not_reapply_fixed_amount_or_minimum(): void
    {
        $increase = $this->calculator->quoteIncrease(
            FeeChannel::Direct,
            new FeeRate(300, 30, 100),
            FeePayer::CustomerPaid,
            FeeBasis::ofSubtotal(1_000),
        );

        $this->assertSame(30, $increase->feeCents);
        $this->assertSame(['basis_points' => 300, 'fixed_cents' => 0, 'minimum_cents' => null], $increase->rate->toArray());
    }

    public function test_increase_is_rounded_on_its_own(): void
    {
        // 3% of $10.50 is 31.5 -> 32 on each part, even though 3% of $21.00 is 63.
        $original = $this->quote(1_050);
        $increase = $this->calculator->quoteIncrease(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            FeeBasis::ofSubtotal(1_050),
        );

        $this->assertSame(32, $original->feeCents);
        $this->assertSame(32, $increase->feeCents);
    }

    public function test_non_positive_increase_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->quoteIncrease(
            FeeChannel::Direct,
            FeeRate::defaultFor(FeeChannel::Direct),
            FeePayer::CustomerPaid,
            FeeBasis::ofSubtotal(0),
        );
    }

    public function test_absorbed_increase_is_refused_when_fee_equals_charge(): void
    {
        $increase = $this->calculator->quoteIncrease(
            FeeChannel::Direct,
            new FeeRate(10_000),
            FeePayer::OperatorAbsorbed,
            FeeBasis::ofSubtotal(1_000),
        );

        $this->expectException(FeeExceedsChargeException::class);
        $this->calculator->priceIncreaseStripePayment($increase);
    }

    // ---------------------------------------------------------------
    // Offline payments (D8)
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{int}>
     */
    public static function offlineAmounts(): array
    {
        return [
            'one cent' => [1],
            'deposit' => [2_000],
            'large' => [5_000_000],
        ];
    }

    #[DataProvider('offlineAmounts')]
    public function test_offline_payments_never_carry_a_fee(int $amount): void
    {
        $payment = $this->calculator->offlinePayment($amount);

        $this->assertSame(PaymentKind::Offline, $payment->kind);
        $this->assertSame($amount, $payment->chargeCents);
        $this->assertSame(0, $payment->applicationFeeCents);
        $this->assertNull($payment->stripeApplicationFeeAmount());
    }

    // ---------------------------------------------------------------
    // Input guards
    // ---------------------------------------------------------------

    public function test_first_payment_above_total_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->calculator->firstStripePayment($this->quote(10_000), 10_301);
    }

    /**
     * @return array<string, array{callable(FeeCalculator): mixed}>
     */
    public static function nonPositivePayments(): array
    {
        return [
            'first payment zero' => [fn (FeeCalculator $c) => $c->firstStripePayment($c->quote(FeeChannel::Direct, new FeeRate(300), FeePayer::CustomerPaid, FeeBasis::ofSubtotal(10_000)), 0)],
            'balance zero' => [fn (FeeCalculator $c) => $c->balanceStripePayment(0)],
            'balance negative' => [fn (FeeCalculator $c) => $c->balanceStripePayment(-5)],
            'offline zero' => [fn (FeeCalculator $c) => $c->offlinePayment(0)],
        ];
    }

    /**
     * @param  callable(FeeCalculator): mixed  $call
     */
    #[DataProvider('nonPositivePayments')]
    public function test_non_positive_payments_are_rejected(callable $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $call($this->calculator);
    }
}
