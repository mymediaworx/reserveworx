<?php

namespace Tests\Feature;

use Tests\TestCase;

class HomeTest extends TestCase
{
    public function test_the_platform_host_renders_the_placeholder(): void
    {
        $this->get('http://localhost/')
            ->assertOk()
            ->assertSee('ReserveWorx');
    }

    public function test_the_health_endpoint_responds_on_the_platform_host(): void
    {
        $this->get('http://localhost/up')->assertOk();
    }
}
