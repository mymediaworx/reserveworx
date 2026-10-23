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
