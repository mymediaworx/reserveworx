<?php

namespace Tests\Unit;

use App\Support\Hostname;
use PHPUnit\Framework\TestCase;

class HostnameTest extends TestCase
{
    public function test_it_normalizes_case_port_and_trailing_dot(): void
    {
        $this->assertSame('booking.example.com', Hostname::normalize(' Booking.Example.com:443. '));
    }

    public function test_it_accepts_a_normal_hostname(): void
    {
        $this->assertTrue(Hostname::isValid('app.reserveworx.com'));
    }

    public function test_it_rejects_a_bare_label_and_a_scheme(): void
    {
        $this->assertFalse(Hostname::isValid('localhost'));
        $this->assertFalse(Hostname::isValid('https://booking.example.com'));
    }
}
