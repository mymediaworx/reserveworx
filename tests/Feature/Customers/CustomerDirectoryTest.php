<?php

namespace Tests\Feature\Customers;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class CustomerDirectoryTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_staff_can_search_customers_in_their_workspace(): void
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($workspace);

        $ada = Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Ada Kayak',
            'email' => 'ada@example.com',
            'phone' => '312-555-0100',
        ]);
        Customer::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'name' => 'Bea Paddle',
            'email' => 'bea@example.com',
            'phone' => '312-555-0199',
        ]);
        $foreign = Customer::query()->create([
            'tenant_id' => $other['tenant']->id,
            'name' => 'Ada Other',
            'email' => 'ada-other@example.com',
            'phone' => '312-555-0100',
        ]);
        $this->booking($workspace['tenant']->id, $ada->id);

        $listed = $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers'))
            ->assertOk();
        $names = collect($listed->json('data'))->pluck('name')->all();
        $this->assertSame(['Ada Kayak', 'Bea Paddle'], $names);
        $this->assertSame(1, $listed->json('data.0.bookings_count'));

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers?q=555-0100'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ada->id)
            ->assertJsonPath('data.0.phone', '312-555-0100');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers?q='.rawurlencode('%')))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$ada->id))
            ->assertOk()
            ->assertJsonPath('data.email', 'ada@example.com')
            ->assertJsonPath('data.bookings_count', 1);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers/'.$foreign->id))
            ->assertNotFound();

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/customers?q='.str_repeat('a', 256)))
            ->assertStatus(422);
    }

    private function booking(string $tenantId, string $customerId): void
    {
        $location = Location::query()->create([
            'tenant_id' => $tenantId,
            'name' => 'Harbor',
            'timezone' => 'America/Chicago',
        ]);
        $service = Service::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'name' => 'Kayak',
            'kind' => ServiceKind::Rental,
            'min_participants' => 1,
            'max_participants' => 4,
            'waitlist_enabled' => false,
        ]);
        Booking::query()->create([
            'tenant_id' => $tenantId,
            'location_id' => $location->id,
            'service_id' => $service->id,
            'customer_id' => $customerId,
            'status' => BookingStatus::Confirmed,
            'payment_status' => PaymentStatus::Unpaid,
            'operational_status' => OperationalStatus::NotArrived,
            'starts_at' => '2026-06-16 15:00:00',
            'ends_at' => '2026-06-16 16:00:00',
            'participants' => 1,
            'payload_hash' => hash('sha256', $customerId),
        ]);
    }
}
