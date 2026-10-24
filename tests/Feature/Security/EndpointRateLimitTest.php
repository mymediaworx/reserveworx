<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class EndpointRateLimitTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_availability_and_checkout_refuse_the_next_call_after_the_limit(): void
    {
        config([
            'reserveworx.availability_rate_per_minute' => 2,
            'reserveworx.checkout_rate_per_minute' => 2,
        ]);

        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $availability = $this->tenantUrl('dock.test', '/api/v1/availability');
        $checkout = $this->tenantUrl('dock.test', '/api/v1/bookings/01ARZ3NDEKTSV4RRFFQ69G5FAV/checkout-session');

        $this->getJson($availability)->assertStatus(422);
        $this->getJson($availability)->assertStatus(422);
        $this->getJson($availability)->assertStatus(429);

        $this->postJson($checkout, [])->assertStatus(404);
        $this->postJson($checkout, [])->assertStatus(404);
        $this->postJson($checkout, [])->assertStatus(429);
    }

    public function test_a_second_user_keeps_a_separate_limit(): void
    {
        config(['reserveworx.availability_rate_per_minute' => 1]);

        $first = $this->workspace('dock.test');
        $this->actingAsWorkspace($first);
        $availability = $this->tenantUrl('dock.test', '/api/v1/availability');
        $this->getJson($availability)->assertStatus(422);
        $this->getJson($availability)->assertStatus(429);

        $second = $this->workspace('pier.test');
        $this->actingAsWorkspace($second);
        $this->getJson($this->tenantUrl('pier.test', '/api/v1/availability'))->assertStatus(422);
    }
}
