<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\BookingReminder;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingReminder>
 */
class BookingReminderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offset_minutes' => 1440,
            'tenant_id' => Tenant::factory(),
            'booking_id' => function (array $attributes): string {
                $tenantId = $attributes['tenant_id'];
                $tenant = Tenant::query()->findOrFail($tenantId);
                $location = Location::factory()->create([
                    'tenant_id' => $tenantId,
                    'timezone' => 'UTC',
                ]);
                app(TenantManager::class)->set($tenant);

                try {
                    $service = Service::query()->create([
                        'tenant_id' => $tenantId,
                        'location_id' => $location->id,
                        'name' => 'Reminder rental',
                        'kind' => ServiceKind::Rental,
                    ]);
                    $start = CarbonImmutable::now('UTC')->addDay();
                    $booking = Booking::query()->create([
                        'tenant_id' => $tenantId,
                        'location_id' => $location->id,
                        'service_id' => $service->id,
                        'status' => BookingStatus::Confirmed,
                        'payment_status' => PaymentStatus::Unpaid,
                        'operational_status' => OperationalStatus::NotArrived,
                        'starts_at' => $start,
                        'ends_at' => $start->addHour(),
                        'participants' => 1,
                        'payload_hash' => str_repeat('a', 64),
                    ]);
                } finally {
                    app(TenantManager::class)->forget();
                }

                return $booking->id;
            },
        ];
    }
}
