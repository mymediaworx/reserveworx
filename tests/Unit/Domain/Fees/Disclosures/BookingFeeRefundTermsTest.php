<?php

namespace Tests\Unit\Domain\Fees\Disclosures;

use App\Domain\Fees\Disclosures\BookingFeeRefundTerms;
use App\Domain\Fees\Disclosures\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class BookingFeeRefundTermsTest extends TestCase
{
    public function test_covers_every_customer_facing_row_of_section_7_7(): void
    {
        $lines = (new BookingFeeRefundTerms)->lines('Lake Paddle Co');

        // Dispute won and dispute lost are tenant-platform outcomes, not customer terms.
        $this->assertSame([
            'operator_cancels',
            'platform_fault',
            'customer_full_refund',
            'customer_partial_refund',
            'customer_no_refund',
            'price_reduction',
            'no_show',
        ], array_keys($lines));
    }

    public function test_states_each_rule_in_plain_language(): void
    {
        $lines = (new BookingFeeRefundTerms)->lines('Lake Paddle Co');

        $this->assertStringContainsString('full refund, including the booking fee', $lines['operator_cancels']);
        $this->assertStringContainsString('weather', $lines['operator_cancels']);
        $this->assertStringContainsString('full refund, including the booking fee', $lines['platform_fault']);
        $this->assertStringContainsString('booking fee is refunded too', $lines['customer_full_refund']);
        $this->assertStringContainsString('same proportion', $lines['customer_partial_refund']);
        $this->assertStringContainsString('booking fee is not refunded', $lines['customer_no_refund']);
        $this->assertStringContainsString('proportion to the price reduction', $lines['price_reduction']);
        $this->assertStringContainsString('as if you had cancelled', $lines['no_show']);
        $this->assertStringContainsString('Lake Paddle Co', $lines['operator_cancels']);
    }

    public function test_never_uses_forbidden_fee_descriptions(): void
    {
        $body = strtolower((new BookingFeeRefundTerms)->body('Lake Paddle Co'));

        foreach (BookingFeeRefundTerms::FORBIDDEN_TERMS as $term) {
            $this->assertStringNotContainsString($term, $body);
        }

        $this->assertSame('Booking fee', BookingFeeRefundTerms::LABEL);
    }

    public function test_body_is_one_sentence_per_line(): void
    {
        $terms = new BookingFeeRefundTerms;

        $this->assertSame(array_values($terms->lines('X')), explode("\n", $terms->body('X')));
    }

    public function test_requires_a_business_name(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BookingFeeRefundTerms)->lines('   ');
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function moneyCases(): array
    {
        return [
            'dollars' => [11_000, 'USD', '$110.00'],
            'cents' => [5, 'USD', '$0.05'],
            'thousands' => [123_456_789, 'USD', '$1,234,567.89'],
            'zero' => [0, 'USD', '$0.00'],
            'negative' => [-150, 'USD', '-$1.50'],
            'other currency' => [2_500, 'cad', 'CAD 25.00'],
        ];
    }

    #[DataProvider('moneyCases')]
    public function test_money_formatting(int $cents, string $currency, string $expected): void
    {
        $this->assertSame($expected, Money::format($cents, $currency));
    }
}
