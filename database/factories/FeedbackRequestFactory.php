<?php

namespace Database\Factories;

use App\Enums\BookingStatus;
use App\Enums\OperationalStatus;
use App\Enums\PaymentStatus;
use App\Enums\ServiceKind;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\FeedbackRequest;
use App\Models\Location;
use App\Models\Service;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<FeedbackRequest>
 */
class FeedbackRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'booking_id' => function (array $attributes): string {
                $tenantId = $attributes['tenant_id'];
                $tenant = Tenant::query()->findOrFail($tenantId);
                $manager = app(TenantManager::class);
                $previous = $manager->get();
                $manager->set($tenant);

                try {
                    $location = Location::factory()->create([
                        'tenant_id' => $tenantId,
                        'timezone' => 'UTC',
                    ]);
                    $service = Service::query()->create([
                        'tenant_id' => $tenantId,
                        'location_id' => $location->id,
                        'name' => 'Feedback rental',
                        'kind' => ServiceKind::Rental,
                    ]);
                    $customer = Customer::query()->create([
                        'tenant_id' => $tenantId,
                        'name' => 'Feedback Guest',
                        'email' => 'guest-'.Str::lower(Str::random(8)).'@example.com',
                    ]);
                    $start = CarbonImmutable::now('UTC')->subDay();
                    $booking = Booking::query()->create([
                        'tenant_id' => $tenantId,
                        'location_id' => $location->id,
                        'service_id' => $service->id,
                        'customer_id' => $customer->id,
                        'status' => BookingStatus::Confirmed,
                        'payment_status' => PaymentStatus::Paid,
                        'operational_status' => OperationalStatus::NotArrived,
                        'starts_at' => $start,
                        'ends_at' => $start->addHour(),
                        'participants' => 1,
                        'payload_hash' => str_repeat('c', 64),
                    ]);
                } finally {
                    if ($previous instanceof Tenant) {
                        $manager->set($previous);
                    } else {
                        $manager->forget();
                    }
                }

                return $booking->id;
            },
            'customer_id' => function (array $attributes): ?string {
                $booking = Booking::withoutGlobalScopes()->find($attributes['booking_id']);

                return $booking instanceof Booking ? $booking->customer_id : null;
            },
            'send_at' => now(),
            'sent_at' => now(),
            'rating' => 5,
            'comment' => 'Smooth water.',
            'rated_at' => now(),
        ];
    }
}
