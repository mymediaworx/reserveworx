<?php

namespace Tests\Feature\Disclosures;

use App\Domain\Fees\Disclosures\BookingFeeRefundTerms;
use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Enums\FeePayer;
use App\Models\DisclosureVersion;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ViewException;
use Tests\TestCase;

class DisclosurePartialsTest extends TestCase
{
    use RefreshDatabase;

    private function terms(string $name = 'Lake Paddle Co'): DisclosureVersion
    {
        $tenant = Tenant::factory()->create(['name' => $name]);
        app(TenantManager::class)->set($tenant);

        $terms = app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($tenant, FeePayer::CustomerPaid);
        $this->assertNotNull($terms);

        return $terms;
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    private function assertNoForbiddenTerms(string $html): void
    {
        foreach (BookingFeeRefundTerms::FORBIDDEN_TERMS as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $html);
        }
    }

    public function test_customer_paid_checkout_itemizes_and_discloses_refund_terms(): void
    {
        $terms = $this->terms();

        $html = view('disclosures.checkout-summary', [
            'subtotalCents' => 10_000,
            'bookingFeeCents' => 300,
            'taxCents' => 700,
            'totalCents' => 11_000,
            'refundTerms' => $terms,
        ])->render();

        $this->assertMatchesRegularExpression('/Subtotal.*\$100\.00.*Booking fee.*\$3\.00.*Taxes.*\$7\.00.*Total.*\$110\.00/s', $html);
        foreach ($terms->lines() as $line) {
            $this->assertStringContainsString(e($line), $html);
        }
        $this->assertStringContainsString('data-disclosure-version="1"', $html);
        $this->assertNoForbiddenTerms($html);
    }

    public function test_operator_absorbed_checkout_shows_no_fee_line_or_fee_terms(): void
    {
        $html = view('disclosures.checkout-summary', [
            'subtotalCents' => 10_000,
            'bookingFeeCents' => null,
            'taxCents' => 700,
            'totalCents' => 10_700,
        ])->render();

        $this->assertStringNotContainsString('Booking fee', $html);
        $this->assertStringContainsString('$107.00', $html);
    }

    public function test_customer_paid_checkout_without_refund_terms_refuses_to_render(): void
    {
        $this->expectException(ViewException::class);

        view('disclosures.checkout-summary', [
            'subtotalCents' => 10_000,
            'bookingFeeCents' => 300,
            'taxCents' => 700,
            'totalCents' => 11_000,
        ])->render();
    }

    public function test_receipt_itemizes_the_fee_and_shows_the_terms_version(): void
    {
        $terms = $this->terms();

        $html = view('disclosures.receipt', [
            'subtotalCents' => 10_000,
            'bookingFeeCents' => 300,
            'taxCents' => 700,
            'totalCents' => 11_000,
            'paidCents' => 11_000,
            'refundTerms' => $terms,
        ])->render();

        $this->assertStringContainsString('Booking fee', $html);
        $this->assertStringContainsString('$3.00', $html);
        $this->assertStringContainsString('Terms version 1', $html);
        $this->assertStringNotContainsString('Refunded', $html);
        $this->assertNoForbiddenTerms($html);
    }

    public function test_refund_receipt_itemizes_the_booking_fee_refunded(): void
    {
        $html = view('disclosures.receipt', [
            'subtotalCents' => 10_000,
            'bookingFeeCents' => 300,
            'taxCents' => 700,
            'totalCents' => 11_000,
            'paidCents' => 11_000,
            'refundedCents' => 5_500,
            'refundedBookingFeeCents' => 150,
            'refundTerms' => $this->terms(),
        ])->render();

        $this->assertMatchesRegularExpression('/Refunded.*\$55\.00.*Booking fee refunded.*\$1\.50/s', $html);
    }

    public function test_a_required_fee_is_its_own_line_and_its_name_is_escaped(): void
    {
        $html = view('disclosures.checkout-summary', [
            'subtotalCents' => 10_000,
            'operatorFees' => [
                ['name' => 'Cleaning', 'total_cents' => 2_500],
                ['name' => '<script>', 'total_cents' => 100],
            ],
            'bookingFeeCents' => 375,
            'taxCents' => 0,
            'totalCents' => 12_975,
            'refundTerms' => $this->terms(),
        ])->render();

        $this->assertMatchesRegularExpression('/Subtotal.*\$100\.00.*Cleaning.*\$25\.00.*Booking fee.*\$3\.75/s', $html);
        $this->assertStringContainsString('class="line operator-fee"', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertNoForbiddenTerms($html);
    }

    public function test_named_tax_lines_replace_the_single_taxes_row_and_are_escaped(): void
    {
        $html = view('disclosures.checkout-summary', [
            'subtotalCents' => 10_000,
            'bookingFeeCents' => 315,
            'taxCents' => 875,
            'taxLines' => [
                ['name' => 'State sales tax', 'cents' => 825],
                ['name' => '<script>', 'cents' => 50],
            ],
            'totalCents' => 11_190,
            'refundTerms' => $this->terms(),
        ])->render();

        $this->assertStringContainsString('State sales tax', $html);
        $this->assertStringContainsString('$8.25', $html);
        $this->assertStringContainsString('$0.50', $html);
        $this->assertStringNotContainsString('<dt>Taxes</dt>', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertNoForbiddenTerms($html);
    }

    public function test_business_name_is_escaped(): void
    {
        $html = view('disclosures.checkout-summary', [
            'subtotalCents' => 100,
            'bookingFeeCents' => 3,
            'taxCents' => 0,
            'totalCents' => 103,
            'refundTerms' => $this->terms('<script>alert(1)</script> Tours'),
        ])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
