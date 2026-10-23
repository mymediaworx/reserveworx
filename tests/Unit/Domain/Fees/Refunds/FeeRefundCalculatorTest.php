<?php

namespace Tests\Unit\Domain\Fees\Refunds;

use App\Domain\Fees\Refunds\FeeRefund;
use App\Domain\Fees\Refunds\FeeRefundCalculator;
use App\Domain\Fees\Refunds\FeeRefundRule;
use App\Domain\Fees\Refunds\FeeRefundState;
use App\Domain\Fees\Refunds\RefundMethod;
use App\Domain\Fees\Refunds\RefundReason;
use App\Domain\Fees\Refunds\StripeFeeRefundMethod;
use App\Enums\FeePayer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One test per row of the spec Section 7.7 table, then proportional rounding and guards.
 *
 * Standard booking: $100.00 subtotal, $7.00 tax, 3% booking fee of $3.00.
 * Customer-paid customer paid $110.00. Operator-absorbed customer paid $107.00.
 */
class FeeRefundCalculatorTest extends TestCase
{
    private FeeRefundCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new FeeRefundCalculator;
    }

    private static function paid(FeePayer $payer): FeeRefundState
    {
        return new FeeRefundState(
            feePayer: $payer,
            subtotalCents: 10_000,
            feeChargedCents: 300,
            paidCents: $payer === FeePayer::CustomerPaid ? 11_000 : 10_700,
        );
    }

    private function assertRefund(
        FeeRefund $refund,
        RefundReason $reason,
        FeeRefundRule $rule,
        int $customer,
        int $customerFee,
        int $applicationFee,
        StripeFeeRefundMethod $stripe,
        bool $handledByDispute = false,
    ): void {
        $this->assertSame($reason, $refund->reason, 'reason code');
        $this->assertSame($rule, $refund->rule, 'rule applied');
        $this->assertSame($customer, $refund->customerRefundCents, 'customer refund');
        $this->assertSame($customerFee, $refund->customerFeeRefundCents, 'booking fee within customer refund');
        $this->assertSame($applicationFee, $refund->applicationFeeRefundCents, 'application fee refund');
        $this->assertSame($stripe, $refund->stripeMethod, 'stripe method');
        $this->assertSame($handledByDispute, $refund->customerHandledByDispute, 'dispute handling');
    }

    // ---------------------------------------------------------------
    // Spec 7.7 table, one test per row
    // ---------------------------------------------------------------

    public function test_row_operator_cancels_refunds_everything(): void
    {
        $reason = RefundReason::OperatorCancelled;

        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::CustomerPaid), $reason),
            $reason, FeeRefundRule::OperatorCancels, 11_000, 300, 300, StripeFeeRefundMethod::RefundApplicationFeeFlag,
        );
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::OperatorAbsorbed), $reason),
            $reason, FeeRefundRule::OperatorCancels, 10_700, 0, 300, StripeFeeRefundMethod::RefundApplicationFeeFlag,
        );
    }

    /**
     * @return array<string, array{RefundReason}>
     */
    public static function platformFaults(): array
    {
        return [
            'oversell exception' => [RefundReason::PlatformOversell],
            'payment after hold expiry with no inventory' => [RefundReason::PlatformPaymentAfterHoldExpiry],
            'duplicate charge' => [RefundReason::PlatformDuplicateCharge],
            'platform error' => [RefundReason::PlatformError],
        ];
    }

    #[DataProvider('platformFaults')]
    public function test_row_platform_or_system_fault_refunds_everything(RefundReason $reason): void
    {
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::CustomerPaid), $reason),
            $reason, FeeRefundRule::PlatformFault, 11_000, 300, 300, StripeFeeRefundMethod::RefundApplicationFeeFlag,
        );
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::OperatorAbsorbed), $reason),
            $reason, FeeRefundRule::PlatformFault, 10_700, 0, 300, StripeFeeRefundMethod::RefundApplicationFeeFlag,
        );
    }

    public function test_row_customer_cancels_with_full_refund_refunds_everything(): void
    {
        $reason = RefundReason::CustomerCancelledFullRefund;

        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::CustomerPaid), $reason),
            $reason, FeeRefundRule::CustomerFullRefund, 11_000, 300, 300, StripeFeeRefundMethod::RefundApplicationFeeFlag,
        );
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::OperatorAbsorbed), $reason),
            $reason, FeeRefundRule::CustomerFullRefund, 10_700, 0, 300, StripeFeeRefundMethod::RefundApplicationFeeFlag,
        );
    }

    public function test_row_customer_cancels_with_partial_refund_is_proportional(): void
    {
        $reason = RefundReason::CustomerCancelledPartialRefund;

        // Policy refunds 50% of the subtotal ($50.00) and the matching tax ($3.50). Fee share: $1.50.
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::CustomerPaid), $reason, 5_000, 350),
            $reason, FeeRefundRule::CustomerPartialRefund, 5_000 + 350 + 150, 150, 150, StripeFeeRefundMethod::ApplicationFeeRefund,
        );
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::OperatorAbsorbed), $reason, 5_000, 350),
            $reason, FeeRefundRule::CustomerPartialRefund, 5_000 + 350, 0, 150, StripeFeeRefundMethod::ApplicationFeeRefund,
        );
    }

    public function test_row_customer_cancels_with_no_refund_refunds_nothing(): void
    {
        $reason = RefundReason::CustomerCancelledNoRefund;

        foreach ([FeePayer::CustomerPaid, FeePayer::OperatorAbsorbed] as $payer) {
            $this->assertRefund(
                $this->calculator->calculate(self::paid($payer), $reason),
                $reason, FeeRefundRule::CustomerNoRefund, 0, 0, 0, StripeFeeRefundMethod::None,
            );
        }
    }

    public function test_row_booking_modified_to_lower_price_is_proportional_to_the_reduction(): void
    {
        $reason = RefundReason::PriceReduced;

        // Subtotal reduced by $20.00 with $1.40 tax. Fee share: 20% of $3.00 = $0.60.
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::CustomerPaid), $reason, 2_000, 140),
            $reason, FeeRefundRule::PriceReduction, 2_000 + 140 + 60, 60, 60, StripeFeeRefundMethod::ApplicationFeeRefund,
        );
        $this->assertRefund(
            $this->calculator->calculate(self::paid(FeePayer::OperatorAbsorbed), $reason, 2_000, 140),
            $reason, FeeRefundRule::PriceReduction, 2_000 + 140, 0, 60, StripeFeeRefundMethod::ApplicationFeeRefund,
        );
    }

    public function test_row_dispute_lost_returns_the_full_fee_and_leaves_the_customer_to_the_dispute(): void
    {
        $reason = RefundReason::DisputeLost;

        foreach ([FeePayer::CustomerPaid, FeePayer::OperatorAbsorbed] as $payer) {
            $refund = $this->calculator->calculate(self::paid($payer), $reason);

            $this->assertRefund(
                $refund, $reason, FeeRefundRule::DisputeLost, 0, 0, 300, StripeFeeRefundMethod::ApplicationFeeRefund, handledByDispute: true,
            );
            $this->assertSame(RefundMethod::FeeOnly, $refund->method);
        }
    }

    public function test_row_dispute_won_refunds_nothing(): void
    {
        $reason = RefundReason::DisputeWon;

        foreach ([FeePayer::CustomerPaid, FeePayer::OperatorAbsorbed] as $payer) {
            $this->assertRefund(
                $this->calculator->calculate(self::paid($payer), $reason),
                $reason, FeeRefundRule::DisputeWon, 0, 0, 0, StripeFeeRefundMethod::None,
            );
        }
    }

    public function test_row_no_show_follows_the_tenant_policy_as_a_customer_cancellation(): void
    {
        $customerPaid = self::paid(FeePayer::CustomerPaid);

        $this->assertRefund(
            $this->calculator->calculate($customerPaid, RefundReason::NoShowFullRefund),
            RefundReason::NoShowFullRefund, FeeRefundRule::NoShow, 11_000, 300, 300, StripeFeeRefundMethod::RefundApplicationFeeFlag,
        );
        $this->assertRefund(
            $this->calculator->calculate($customerPaid, RefundReason::NoShowPartialRefund, 2_500, 175),
            RefundReason::NoShowPartialRefund, FeeRefundRule::NoShow, 2_500 + 175 + 75, 75, 75, StripeFeeRefundMethod::ApplicationFeeRefund,
        );
        $this->assertRefund(
            $this->calculator->calculate($customerPaid, RefundReason::NoShowNoRefund),
            RefundReason::NoShowNoRefund, FeeRefundRule::NoShow, 0, 0, 0, StripeFeeRefundMethod::None,
        );

        // Same amounts as the matching customer-cancellation rows.
        foreach ([
            [RefundReason::NoShowFullRefund, RefundReason::CustomerCancelledFullRefund, 0, 0],
            [RefundReason::NoShowPartialRefund, RefundReason::CustomerCancelledPartialRefund, 2_500, 175],
            [RefundReason::NoShowNoRefund, RefundReason::CustomerCancelledNoRefund, 0, 0],
        ] as [$noShow, $cancel, $subtotal, $tax]) {
            foreach ([FeePayer::CustomerPaid, FeePayer::OperatorAbsorbed] as $payer) {
                $a = $this->calculator->calculate(self::paid($payer), $noShow, $subtotal, $tax);
                $b = $this->calculator->calculate(self::paid($payer), $cancel, $subtotal, $tax);
                $this->assertSame(
                    [$b->customerRefundCents, $b->applicationFeeRefundCents, $b->stripeMethod],
                    [$a->customerRefundCents, $a->applicationFeeRefundCents, $a->stripeMethod],
                );
            }
        }
    }

    public function test_every_reason_code_maps_to_a_table_row_and_method(): void
    {
        $rows = [];

        foreach (RefundReason::cases() as $reason) {
            $rows[$reason->rule()->value] = true;
            $this->assertNotSame('', $reason->rule()->description());
            $this->assertInstanceOf(RefundMethod::class, $reason->method());
        }

        $this->assertCount(count(FeeRefundRule::cases()), $rows, 'every table row has at least one reason code');
    }

    // ---------------------------------------------------------------
    // Proportional rounding
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{int, int, int, int}>
     */
    public static function proportionalCases(): array
    {
        // fee, subtotal, cumulative refunded subtotal, expected fee to have refunded
        return [
            'half' => [300, 10_000, 5_000, 150],
            'exactly half a cent rounds up' => [101, 200, 100, 51],          // 50.5
            'below half a cent rounds down' => [3, 100, 10, 0],              // 0.3
            'above half a cent rounds up' => [3, 100, 20, 1],                // 0.6
            'one third' => [100, 3_000, 1_000, 33],                          // 33.33
            'two thirds' => [100, 3_000, 2_000, 67],                         // 66.67
            'all of it' => [299, 9_973, 9_973, 299],
            'none of it' => [299, 9_973, 0, 0],
            'zero fee' => [0, 10_000, 5_000, 0],
        ];
    }

    #[DataProvider('proportionalCases')]
    public function test_proportional_fee_rounds_half_up(int $fee, int $subtotal, int $cumulative, int $expected): void
    {
        $this->assertSame($expected, FeeRefundCalculator::proportionalFeeFor($fee, $subtotal, $cumulative));
    }

    public function test_three_one_third_refunds_return_exactly_the_whole_fee(): void
    {
        $state = new FeeRefundState(FeePayer::CustomerPaid, 3_000, 100, 3_100);
        $fees = [];

        for ($i = 0; $i < 3; $i++) {
            $refund = $this->calculator->calculate($state, RefundReason::CustomerCancelledPartialRefund, 1_000);
            $fees[] = $refund->applicationFeeRefundCents;
            $state = $this->apply($state, $refund);
        }

        $this->assertSame([33, 34, 33], $fees);
        $this->assertSame(100, array_sum($fees));
        $this->assertSame(0, $state->remainingFeeCents());
        $this->assertSame(0, $state->remainingPaidCents());
    }

    public function test_any_split_of_the_subtotal_returns_exactly_the_whole_fee(): void
    {
        mt_srand(77);

        for ($run = 0; $run < 200; $run++) {
            $subtotal = mt_rand(1, 500_000);
            $fee = mt_rand(1, intdiv($subtotal, 10) + 1);
            $state = new FeeRefundState(FeePayer::OperatorAbsorbed, $subtotal, $fee, $subtotal);
            $totalFee = 0;

            while ($state->remainingSubtotalCents() > 0) {
                $chunk = min($state->remainingSubtotalCents(), mt_rand(1, max(1, intdiv($subtotal, 3))));
                $refund = $this->calculator->calculate($state, RefundReason::PriceReduced, $chunk);

                $this->assertGreaterThanOrEqual(0, $refund->applicationFeeRefundCents);
                $totalFee += $refund->applicationFeeRefundCents;
                $state = $this->apply($state, $refund);
            }

            $this->assertSame($fee, $totalFee, "run {$run}: subtotal {$subtotal}, fee {$fee}");
        }
    }

    public function test_cumulative_rounding_avoids_drift_from_rounding_each_refund_alone(): void
    {
        // 3 cents of fee on $1.00; ten 10-cent refunds. Rounding each alone gives 0 x 10 = 0.
        $state = new FeeRefundState(FeePayer::OperatorAbsorbed, 100, 3, 100);
        $total = 0;

        for ($i = 0; $i < 10; $i++) {
            $refund = $this->calculator->calculate($state, RefundReason::CustomerCancelledPartialRefund, 10);
            $total += $refund->applicationFeeRefundCents;
            $state = $this->apply($state, $refund);
        }

        $this->assertSame(3, $total);
    }

    // ---------------------------------------------------------------
    // Prior refunds, deposits, and zero fees
    // ---------------------------------------------------------------

    public function test_full_refund_after_a_partial_refund_returns_only_the_remainder_without_the_stripe_flag(): void
    {
        $state = self::paid(FeePayer::CustomerPaid);
        $state = $this->apply($state, $this->calculator->calculate($state, RefundReason::PriceReduced, 5_000, 350));

        $refund = $this->calculator->calculate($state, RefundReason::OperatorCancelled);

        $this->assertSame(150, $refund->applicationFeeRefundCents);
        $this->assertSame(11_000 - 5_500, $refund->customerRefundCents);
        $this->assertSame(StripeFeeRefundMethod::ApplicationFeeRefund, $refund->stripeMethod);
    }

    public function test_prior_manual_fee_refund_above_the_proportional_share_is_not_exceeded(): void
    {
        $state = new FeeRefundState(FeePayer::OperatorAbsorbed, 10_000, 300, 10_700, refundedFeeCents: 200);

        $refund = $this->calculator->calculate($state, RefundReason::CustomerCancelledPartialRefund, 5_000);

        $this->assertSame(0, $refund->applicationFeeRefundCents);
        $this->assertSame(StripeFeeRefundMethod::None, $refund->stripeMethod);
    }

    public function test_lost_dispute_after_a_partial_refund_returns_the_remaining_fee(): void
    {
        $state = new FeeRefundState(FeePayer::CustomerPaid, 10_000, 300, 11_000, 2_000, 60, 2_200);

        $this->assertSame(240, $this->calculator->calculate($state, RefundReason::DisputeLost)->applicationFeeRefundCents);
    }

    public function test_full_refund_of_a_deposit_only_booking_returns_the_deposit_and_the_full_fee(): void
    {
        // Full fee was collected on a $20.00 deposit.
        $state = new FeeRefundState(FeePayer::CustomerPaid, 10_000, 300, 2_000);
        $refund = $this->calculator->calculate($state, RefundReason::OperatorCancelled);

        $this->assertSame(2_000, $refund->customerRefundCents);
        $this->assertSame(300, $refund->applicationFeeRefundCents);
    }

    public function test_booking_paid_offline_has_no_fee_to_refund(): void
    {
        $state = new FeeRefundState(FeePayer::CustomerPaid, 10_000, 0, 0);
        $refund = $this->calculator->calculate($state, RefundReason::OperatorCancelled);

        $this->assertSame(0, $refund->applicationFeeRefundCents);
        $this->assertSame(0, $refund->customerRefundCents);
        $this->assertSame(StripeFeeRefundMethod::None, $refund->stripeMethod);
    }

    public function test_audit_record_has_reason_rule_and_amounts(): void
    {
        $audit = $this->calculator
            ->calculate(self::paid(FeePayer::CustomerPaid), RefundReason::CustomerCancelledPartialRefund, 5_000, 350)
            ->toAuditArray();

        $this->assertSame(FeeRefundCalculator::RULES_VERSION, $audit['rules_version']);
        $this->assertSame('customer_cancelled_partial_refund', $audit['reason_code']);
        $this->assertSame('customer_partial_refund', $audit['rule']);
        $this->assertSame(FeeRefundRule::CustomerPartialRefund->description(), $audit['rule_description']);
        $this->assertSame(5_500, $audit['customer_refund_cents']);
        $this->assertSame(150, $audit['application_fee_refund_cents']);
        $this->assertSame('application_fee_refund', $audit['stripe_method']);
        $this->assertSame(10_000, $audit['before']['subtotal_cents']);
    }

    // ---------------------------------------------------------------
    // Guards
    // ---------------------------------------------------------------

    /**
     * @return array<string, array{callable(FeeRefundCalculator): mixed}>
     */
    public static function invalidRequests(): array
    {
        $state = self::paid(FeePayer::CustomerPaid);

        return [
            'proportional without an amount' => [fn (FeeRefundCalculator $c) => $c->calculate($state, RefundReason::PriceReduced)],
            'proportional negative tax' => [fn (FeeRefundCalculator $c) => $c->calculate($state, RefundReason::PriceReduced, 100, -1)],
            'proportional above the remaining subtotal' => [fn (FeeRefundCalculator $c) => $c->calculate($state, RefundReason::PriceReduced, 10_001)],
            'full reason with amounts' => [fn (FeeRefundCalculator $c) => $c->calculate($state, RefundReason::OperatorCancelled, 100)],
            'no-refund reason with tax' => [fn (FeeRefundCalculator $c) => $c->calculate($state, RefundReason::DisputeWon, 0, 5)],
            'proportional more than was paid' => [fn (FeeRefundCalculator $c) => $c->calculate(
                new FeeRefundState(FeePayer::CustomerPaid, 10_000, 300, 2_000),
                RefundReason::CustomerCancelledPartialRefund,
                5_000,
            )],
        ];
    }

    /**
     * @param  callable(FeeRefundCalculator): mixed  $call
     */
    #[DataProvider('invalidRequests')]
    public function test_invalid_requests_are_rejected(callable $call): void
    {
        $this->expectException(InvalidArgumentException::class);
        $call($this->calculator);
    }

    /**
     * @return array<string, array{callable(): mixed}>
     */
    public static function invalidStates(): array
    {
        return [
            'negative paid' => [fn () => new FeeRefundState(FeePayer::CustomerPaid, 100, 3, -1)],
            'fee without subtotal' => [fn () => new FeeRefundState(FeePayer::CustomerPaid, 0, 3, 3)],
            'refunded subtotal above subtotal' => [fn () => new FeeRefundState(FeePayer::CustomerPaid, 100, 3, 103, 101)],
            'refunded fee above fee' => [fn () => new FeeRefundState(FeePayer::CustomerPaid, 100, 3, 103, 0, 4)],
            'refunded customer above paid' => [fn () => new FeeRefundState(FeePayer::CustomerPaid, 100, 3, 103, 0, 0, 104)],
        ];
    }

    /**
     * @param  callable(): mixed  $make
     */
    #[DataProvider('invalidStates')]
    public function test_invalid_states_are_rejected(callable $make): void
    {
        $this->expectException(InvalidArgumentException::class);
        $make();
    }

    private function apply(FeeRefundState $state, FeeRefund $refund): FeeRefundState
    {
        return new FeeRefundState(
            $state->feePayer,
            $state->subtotalCents,
            $state->feeChargedCents,
            $state->paidCents,
            $state->refundedSubtotalCents + ($refund->method === RefundMethod::Full ? $state->remainingSubtotalCents() : $refund->refundSubtotalCents),
            $state->refundedFeeCents + $refund->applicationFeeRefundCents,
            $state->refundedCustomerCents + $refund->customerRefundCents,
        );
    }
}
