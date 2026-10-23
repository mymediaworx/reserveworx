<?php

namespace App\Domain\Fees\Disclosures;

use InvalidArgumentException;

/**
 * Writes the plain-language booking fee refund summary shown before payment
 * (spec Section 7.6 item 4), generated from the Section 7.7 rules.
 *
 * Pure: no database access. Only customer-paid bookings show a booking fee,
 * so only that mode has customer-facing refund terms. Dispute outcomes are
 * between the tenant and the platform and are not customer terms.
 */
final class BookingFeeRefundTerms
{
    /** Bump when the wording or the rules it reflects change. */
    public const RULES_VERSION = 'spec-1.0/7.6-7.7/terms-1';

    public const LABEL = 'Booking fee';

    /**
     * Spec Section 7.6 item 3: the fee is never described with these terms.
     *
     * @var list<string>
     */
    public const FORBIDDEN_TERMS = ['credit card fee', 'surcharge', 'processing fee', 'government'];

    /**
     * One sentence per 7.7 row a customer can be in.
     *
     * @return array<string, string> Rule key => sentence.
     */
    public function lines(string $businessName): array
    {
        $business = trim($businessName);

        if ($business === '') {
            throw new InvalidArgumentException('Business name is required for refund terms.');
        }

        return [
            'operator_cancels' => "If {$business} cancels your booking, including for weather, safety, or too few participants, you get a full refund, including the booking fee.",
            'platform_fault' => 'If a booking system error affects your booking, such as a duplicate charge or an oversold activity, you get a full refund, including the booking fee.',
            'customer_full_refund' => "If you cancel and {$business}'s cancellation policy gives you a full refund, the booking fee is refunded too.",
            'customer_partial_refund' => "If you cancel and {$business}'s cancellation policy gives you a partial refund, the booking fee is refunded in the same proportion as the price.",
            'customer_no_refund' => "If you cancel and {$business}'s cancellation policy gives no refund, the booking fee is not refunded.",
            'price_reduction' => 'If your booking is changed to a lower price, the booking fee is refunded in proportion to the price reduction.',
            'no_show' => "If you do not show up, {$business}'s no-show policy applies as if you had cancelled.",
        ];
    }

    public function body(string $businessName): string
    {
        return implode("\n", $this->lines($businessName));
    }
}
