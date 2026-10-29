<?php

namespace Tests\Feature\Web;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FeedbackRequest;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AssertsZoneAbbreviations;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffFeedbackLocationTimeTest extends TestCase
{
    use AssertsZoneAbbreviations, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_feedback_ratings_name_the_location_clock_including_both_fall_back_hours(): void
    {
        $workspace = $this->workspace('dock.test', 'owner', ['name' => 'Harbor Demo']);
        $other = $this->workspace('creek.test', 'owner', ['name' => 'Creek']);
        TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $workspace['tenant']->id)
            ->update(['timezone' => 'America/Chicago']);

        $harbor = $this->service($workspace['tenant'], 'America/New_York', 'Harbor Kayak');
        $utc = $this->service($workspace['tenant'], 'UTC', 'Utc Kayak');
        $fallback = $this->service($workspace['tenant'], 'Not/AZone', 'Fallback Kayak');
        $creek = $this->service($other['tenant'], 'America/New_York', 'Creek Kayak');

        $early = $this->rating($workspace['tenant'], $harbor, 'Early Fall', 'early@example.com', '2026-11-01 05:30:00', 'Early note');
        $later = $this->rating($workspace['tenant'], $harbor, 'Later Fall', 'later@example.com', '2026-11-01 06:30:00', 'Later note');
        $spring = $this->rating($workspace['tenant'], $harbor, 'Spring Guest', 'spring@example.com', '2026-03-08 07:30:00', 'Spring note');
        $utcRating = $this->rating($workspace['tenant'], $utc, 'Utc Guest', 'utc@example.com', '2026-06-16 18:00:00', 'Utc note');
        $fallbackRating = $this->rating($workspace['tenant'], $fallback, 'Fallback Guest', 'fallback@example.com', '2026-06-16 18:00:00', 'Fallback note');
        $this->rating($other['tenant'], $creek, 'Creek Only', 'creek@example.com', '2026-11-01 05:30:00', 'Creek note');

        $payments = $this->rows(Payment::class);
        $refunds = $this->rows(Refund::class);
        $fees = $this->rows(PlatformFee::class);
        $taxes = $this->rows(LocationTaxRule::class);
        $snapshots = $this->rows(PricingSnapshot::class);
        $prices = $this->rows(PriceRule::class);
        $audits = $this->rows(AuditLog::class);

        $this->actingAs($workspace['user']);

        $fallPage = (string) $this->get($this->tenantUrl('dock.test', '/staff/feedback?service='.$harbor->id))
            ->assertOk()
            ->assertSee('Feedback')
            ->assertSee('Early Fall')
            ->assertSee('Later Fall')
            ->assertSee('Spring Guest')
            ->assertSee('Sun, Nov 1, 1:30 AM EDT')
            ->assertSee('Sun, Nov 1, 1:30 AM EST')
            ->assertSee('Sun, Mar 8, 3:30 AM EDT')
            ->assertSee('data-feedback="'.$early->id.'"', false)
            ->assertSee('data-feedback="'.$later->id.'"', false)
            ->assertSee('data-feedback="'.$spring->id.'"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->assertSee('.feedback-page .when { overflow-wrap: anywhere; max-width: 100%; }', false)
            ->assertDontSee('Utc Guest')
            ->assertDontSee('Fallback Guest')
            ->assertDontSee('Creek Only')
            ->assertDontSee('Creek note')
            ->assertDontSee('America/Chicago')
            ->getContent();
        $this->assertSeeInOrder($fallPage, [
            'Sun, Nov 1, 1:30 AM EST',
            'Sun, Nov 1, 1:30 AM EDT',
            'Sun, Mar 8, 3:30 AM EDT',
        ]);

        $utcPage = $this->get($this->tenantUrl('dock.test', '/staff/feedback?service='.$utc->id))
            ->assertOk()
            ->assertSee('Utc Guest')
            ->assertSee('Tue, Jun 16, 6:00 PM UTC')
            ->assertSee('data-feedback="'.$utcRating->id.'"', false)
            ->assertDontSee('Early Fall');
        $this->assertOmitsZoneAbbreviations($utcPage, 'EDT', 'CDT', 'EST');

        $this->get($this->tenantUrl('dock.test', '/staff/feedback?service='.$fallback->id))
            ->assertOk()
            ->assertSee('Fallback Guest')
            ->assertSee('Tue, Jun 16, 1:00 PM CDT')
            ->assertSee('data-feedback="'.$fallbackRating->id.'"', false)
            ->assertDontSee('Not/AZone')
            ->assertDontSee('1:00 PM EDT')
            ->assertDontSee('Utc Guest');

        $this->assertSame($payments, $this->rows(Payment::class));
        $this->assertSame($refunds, $this->rows(Refund::class));
        $this->assertSame($fees, $this->rows(PlatformFee::class));
        $this->assertSame($taxes, $this->rows(LocationTaxRule::class));
        $this->assertSame($snapshots, $this->rows(PricingSnapshot::class));
        $this->assertSame($prices, $this->rows(PriceRule::class));
        $this->assertSame($audits, $this->rows(AuditLog::class));

        $storedEarly = FeedbackRequest::withoutGlobalScope(TenantScope::class)->findOrFail($early->id);
        $storedLater = FeedbackRequest::withoutGlobalScope(TenantScope::class)->findOrFail($later->id);
        $storedSpring = FeedbackRequest::withoutGlobalScope(TenantScope::class)->findOrFail($spring->id);
        $this->assertSame('2026-11-01 05:30:00', $storedEarly->rated_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-01 06:30:00', $storedLater->rated_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-08 07:30:00', $storedSpring->rated_at?->utc()->format('Y-m-d H:i:s'));
        $this->assertNotSame($workspace['tenant']->id, $other['tenant']->id);
    }

    private function service(Tenant $tenant, string $timezone, string $name): Service
    {
        $location = Location::factory()->create([
            'tenant_id' => $tenant->id,
            'timezone' => $timezone,
            'name' => $name.' Dock',
        ]);
        app(TenantManager::class)->set($tenant);
        $service = Service::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'name' => $name,
            'kind' => ServiceKind::Rental,
        ]);
        app(TenantManager::class)->forget();

        return $service;
    }

    private function rating(Tenant $tenant, Service $service, string $customerName, string $email, string $ratedUtc, string $comment): FeedbackRequest
    {
        app(TenantManager::class)->set($tenant);
        $customer = Customer::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $customerName,
            'email' => $email,
        ]);
        $booking = Booking::query()->create([
            'tenant_id' => $tenant->id,
            'location_id' => $service->location_id,
            'service_id' => $service->id,
            'customer_id' => $customer->id,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::Completed,
            'starts_at' => CarbonImmutable::parse('2026-11-01 12:00:00', 'UTC'),
            'ends_at' => CarbonImmutable::parse('2026-11-01 14:00:00', 'UTC'),
            'participants' => 1,
            'payload_hash' => hash('sha256', $email),
        ]);
        $rating = FeedbackRequest::query()->create([
            'tenant_id' => $tenant->id,
            'booking_id' => $booking->id,
            'customer_id' => $customer->id,
            'send_at' => CarbonImmutable::parse($ratedUtc, 'UTC')->subHour(),
            'sent_at' => CarbonImmutable::parse($ratedUtc, 'UTC')->subHour(),
            'rating' => 5,
            'comment' => $comment,
            'rated_at' => CarbonImmutable::parse($ratedUtc, 'UTC'),
        ]);
        app(TenantManager::class)->forget();

        return $rating;
    }

    /**
     * @param  class-string  $model
     */
    private function rows(string $model): int
    {
        return $model::withoutGlobalScope(TenantScope::class)->count();
    }

    /**
     * @param  list<string>  $needles
     */
    private function assertSeeInOrder(string $html, array $needles): void
    {
        $offset = 0;

        foreach ($needles as $needle) {
            $position = strpos($html, $needle, $offset);
            $this->assertNotFalse($position, $needle);
            $offset = $position + strlen($needle);
        }
    }
}
