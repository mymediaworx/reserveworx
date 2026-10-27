<?php

namespace Tests\Feature;

use App\Domain\Fees\Disclosures\BookingFeeRefundTerms;
use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Enums\BookingStatus;
use App\Enums\FeePayer;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\DisclosureVersion;
use App\Models\Location;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Notifications\BookingConfirmedNotification;
use App\Services\Notifications\BookingConfirmationMail;
use App\Services\Notifications\BookingEvents;
use App\Services\Widget\WidgetTokens;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ConfirmationFeeDisclosureTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        URL::forceRootUrl(null);
        parent::tearDown();
    }

    public function test_the_confirmation_page_itemizes_the_stored_booking_fee_and_refund_terms(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek Outfitters']);
        $this->useTenant($workspace['tenant']);
        $terms = app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($workspace['tenant'], FeePayer::CustomerPaid);
        $this->assertNotNull($terms);
        $sentence = $terms->lines()[0];

        $paid = $this->booking($workspace['tenant'], 'Ada Kayak', 'ada@example.com');
        $this->snapshot($paid, [
            'services_cents' => 10_000,
            'discount_cents' => 0,
            'add_ons_cents' => 500,
            'add_ons' => [['name' => 'Dry bag', 'total_cents' => 500, 'quantity' => 1]],
            'customer_fee_line_cents' => 300,
            'tax_cents' => 700,
            'tax_lines' => [['name' => 'State sales tax', 'cents' => 700]],
            'customer_total_cents' => 11_500,
        ], $terms->id);
        $this->payment($paid, 11_500);

        $zero = $this->booking($workspace['tenant'], 'Bea Zero', 'bea@example.com');
        $this->snapshot($zero, [
            'services_cents' => 2_000,
            'discount_cents' => 0,
            'add_ons_cents' => 0,
            'customer_fee_line_cents' => 0,
            'tax_cents' => 0,
            'customer_total_cents' => 2_000,
        ], $terms->id);

        $absorbed = $this->booking($workspace['tenant'], 'Cal Absorbed', 'cal@example.com');
        $this->snapshot($absorbed, [
            'services_cents' => 4_000,
            'discount_cents' => 0,
            'add_ons_cents' => 0,
            'customer_fee_line_cents' => null,
            'tax_cents' => 200,
            'tax_lines' => [['name' => 'Harbor tax', 'cents' => 200]],
            'customer_total_cents' => 4_200,
        ]);

        $missing = $this->booking($workspace['tenant'], 'Dee Missing', 'dee@example.com');
        $this->snapshot($missing, [
            'services_cents' => 2_200,
            'customer_fee_line_cents' => 66,
            'customer_total_cents' => 2_266,
        ]);

        $foreign = $this->booking($other['tenant'], 'Eve Creek', 'eve@example.com');
        $this->useTenant($other['tenant']);
        $foreignTerms = app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($other['tenant'], FeePayer::CustomerPaid);
        $this->assertNotNull($foreignTerms);
        $this->snapshot($foreign, [
            'services_cents' => 9_000,
            'customer_fee_line_cents' => 270,
            'customer_total_cents' => 9_270,
        ], $foreignTerms->id);

        $this->useTenant($workspace['tenant']);
        $token = app(WidgetTokens::class)->hostedPlain($workspace['tenant']);
        $before = $this->counts();
        $storedFee = $this->feeLine($paid);

        $page = $this->get($this->signedUrl('dock.test', $paid->id, $token))
            ->assertOk()
            ->assertSee('Dry bag', false)
            ->assertSee('Booking fee', false)
            ->assertSee('$3.00', false)
            ->assertSee('$115.00', false)
            ->assertSee('State sales tax', false)
            ->assertSee($sentence, false)
            ->assertSee('Terms version '.$terms->version, false)
            ->assertSee('data-disclosure-version="'.$terms->version.'"', false)
            ->assertSee('data-confirmation-receipt', false)
            ->assertSee('overflow-wrap: anywhere', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertDontSee('<h2>Taxes</h2>', false)
            ->assertDontSee('Creek Outfitters', false);

        foreach (BookingFeeRefundTerms::FORBIDDEN_TERMS as $term) {
            $page->assertDontSee($term, false);
        }

        $this->assertSame(1, substr_count($page->getContent(), 'class="line booking-fee"'));
        $this->assertSame(1, substr_count($page->getContent(), 'class="booking-fee-refund-terms"'));

        $this->get($this->signedUrl('dock.test', $zero->id, $token))
            ->assertOk()
            ->assertSee('Booking fee', false)
            ->assertSee('$0.00', false)
            ->assertSee('Terms version '.$terms->version, false);

        $absorbedPage = $this->get($this->signedUrl('dock.test', $absorbed->id, $token))
            ->assertOk()
            ->assertSee('$42.00', false)
            ->assertSee('Harbor tax', false)
            ->assertSee('data-confirmation-receipt', false)
            ->assertDontSee('Booking fee', false)
            ->assertDontSee('Terms version', false)
            ->assertDontSee('<h2>Taxes</h2>', false);
        $absorbedPage->assertDontSee('booking fee', false);

        $this->get($this->signedUrl('dock.test', $missing->id, $token))
            ->assertOk()
            ->assertDontSee('Booking fee', false)
            ->assertDontSee('Terms version', false)
            ->assertDontSee('data-confirmation-receipt', false)
            ->assertDontSee($sentence, false);

        $this->get($this->signedUrl('creek.test', $paid->id, $token))->assertNotFound();
        $this->useTenant($other['tenant']);
        $otherToken = app(WidgetTokens::class)->hostedPlain($other['tenant']);
        $this->get($this->signedUrl('creek.test', $paid->id, $otherToken))->assertNotFound();

        $this->assertSame($before, $this->counts());
        $this->assertSame($storedFee, $this->feeLine($paid));
        $this->assertSame($terms->id, PricingSnapshot::withoutGlobalScope(TenantScope::class)->where('booking_id', $paid->id)->value('disclosure_version_id'));
    }

    public function test_the_confirmation_email_itemizes_the_stored_booking_fee_once(): void
    {
        Notification::fake();
        config(['mail.default' => 'array']);
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek Outfitters']);
        $this->useTenant($workspace['tenant']);
        $terms = app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($workspace['tenant'], FeePayer::CustomerPaid);
        $this->assertNotNull($terms);
        $sentence = $terms->lines()[0];

        $paid = $this->booking($workspace['tenant'], 'Ada Kayak', 'ada@example.com');
        $this->snapshot($paid, [
            'services_cents' => 10_000,
            'discount_cents' => 0,
            'add_ons_cents' => 0,
            'customer_fee_line_cents' => 300,
            'tax_cents' => 700,
            'customer_total_cents' => 11_000,
        ], $terms->id);
        $absorbed = $this->booking($workspace['tenant'], 'Cal Absorbed', 'cal@example.com');
        $this->snapshot($absorbed, [
            'services_cents' => 4_000,
            'customer_fee_line_cents' => null,
            'tax_cents' => 0,
            'customer_total_cents' => 4_000,
        ]);
        $missing = $this->booking($workspace['tenant'], 'Dee Missing', 'dee@example.com');
        $this->snapshot($missing, [
            'services_cents' => 2_200,
            'customer_fee_line_cents' => 66,
            'customer_total_cents' => 2_266,
        ]);

        $before = $this->counts();
        $this->useTenant($workspace['tenant']);
        app(BookingEvents::class)->confirmed($paid->fresh() ?? $paid);
        Notification::assertNothingSent();

        config(['mail.default' => 'smtp']);
        $this->useTenant($other['tenant']);
        app(BookingConfirmationMail::class)->send($paid->fresh() ?? $paid);
        Notification::assertNothingSent();

        $this->useTenant($workspace['tenant']);
        foreach ([$paid, $absorbed, $missing] as $booking) {
            app(BookingEvents::class)->confirmed($booking->fresh() ?? $booking);
        }
        app(BookingEvents::class)->confirmed($paid->fresh() ?? $paid);

        $seen = [];
        Notification::assertSentOnDemandTimes(BookingConfirmedNotification::class, 3);
        Notification::assertSentOnDemand(
            BookingConfirmedNotification::class,
            function (BookingConfirmedNotification $notification, array $channels, object $notifiable) use (&$seen): bool {
                $this->assertInstanceOf(AnonymousNotifiable::class, $notifiable);
                $mail = $notification->toMail($notifiable);
                $html = (string) $mail->render();
                $view = $mail->view;
                $this->assertIsArray($view);
                $text = (string) view($view['text'], $mail->viewData)->render();
                $email = $notifiable->routes['mail'] ?? null;
                $this->assertIsString($email);
                $seen[$email] = ['html' => $html, 'text' => $text];
                $this->assertSame(['mail'], $channels);
                $this->assertSame('Your booking is confirmed', $mail->subject);
                $this->assertStringContainsString('overflow-wrap:anywhere', $html);
                $this->assertStringContainsString('max-width:100%', $html);

                return true;
            },
        );

        foreach (['html', 'text'] as $part) {
            $this->assertStringContainsString('Booking fee', $seen['ada@example.com'][$part]);
            $this->assertStringContainsString('$3.00', $seen['ada@example.com'][$part]);
            $this->assertStringContainsString('$110.00', $seen['ada@example.com'][$part]);
            $this->assertStringContainsString($sentence, $seen['ada@example.com'][$part]);
            $this->assertStringContainsString('Terms version '.$terms->version, $seen['ada@example.com'][$part]);
            $this->assertStringContainsString('@media (max-width: 390px)', $seen['ada@example.com']['html']);
            $this->assertStringContainsString('$40.00', $seen['cal@example.com'][$part]);
            $this->assertStringNotContainsString('Booking fee', $seen['cal@example.com'][$part]);
            $this->assertStringNotContainsString('Terms version', $seen['cal@example.com'][$part]);
            $this->assertStringNotContainsString('Booking fee', $seen['dee@example.com'][$part]);
            $this->assertStringNotContainsString('Terms version', $seen['dee@example.com'][$part]);
            $this->assertStringNotContainsString($sentence, $seen['dee@example.com'][$part]);
            $this->assertStringNotContainsString('Creek Outfitters', $seen['ada@example.com'][$part]);
        }

        foreach (BookingFeeRefundTerms::FORBIDDEN_TERMS as $term) {
            $this->assertStringNotContainsStringIgnoringCase($term, $seen['ada@example.com']['html']);
            $this->assertStringNotContainsStringIgnoringCase($term, $seen['ada@example.com']['text']);
        }

        $this->assertSame($before, $this->counts());
    }

    /**
     * @return array{payments: int, snapshots: int, refunds: int, fees: int, rules: int, disclosures: int}
     */
    private function counts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'snapshots' => PricingSnapshot::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'rules' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'disclosures' => DisclosureVersion::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }

    private function feeLine(Booking $booking): mixed
    {
        $payload = PricingSnapshot::withoutGlobalScope(TenantScope::class)
            ->where('booking_id', $booking->id)
            ->value('payload');

        return is_array($payload) ? ($payload['customer_fee_line_cents'] ?? null) : null;
    }

    private function useTenant(Tenant $tenant): void
    {
        app(TenantManager::class)->set($tenant);
    }

    private function booking(Tenant $tenant, string $name, string $email): Booking
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => 'America/New_York',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => 'Kayak rental',
            'kind' => ServiceKind::Rental,
        ]);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'email' => $email,
        ]);
        $start = CarbonImmutable::parse('2026-06-16 18:00:00', 'UTC');

        return Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Paid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => $start,
            'ends_at' => $start->addHour(),
            'participants' => 1,
            'payload_hash' => hash('sha256', $email),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function snapshot(Booking $booking, array $payload, ?string $disclosureId = null): void
    {
        PricingSnapshot::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'currency' => 'USD',
            'rules_version' => 'spec-1',
            'disclosure_version_id' => $disclosureId,
            'payload' => $payload,
        ]);
    }

    private function payment(Booking $booking, int $cents): void
    {
        Payment::query()->create([
            'tenant_id' => $booking->tenant_id,
            'booking_id' => $booking->id,
            'method' => 'stripe',
            'kind' => 'checkout',
            'status' => 'paid',
            'amount_cents' => $cents,
            'fee_cents' => 0,
        ]);
    }

    private function signedUrl(string $host, string $bookingId, string $token): string
    {
        URL::forceRootUrl('http://'.$host);

        try {
            return URL::signedRoute('widget.confirmation', [
                'token' => $token,
                'booking' => $bookingId,
            ]);
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
