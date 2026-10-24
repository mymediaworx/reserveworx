<?php

namespace Tests\Unit\Webhooks;

use App\Services\Webhooks\PublicHttpsEndpoint;
use App\Services\Webhooks\UnsafeWebhookUrlException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\MapHostAddressLookup;

class PublicHttpsEndpointTest extends TestCase
{
    #[DataProvider('blockedUrls')]
    public function test_private_loopback_and_non_https_urls_are_refused(string $url): void
    {
        $guard = new PublicHttpsEndpoint(new MapHostAddressLookup([
            'hooks.example.com' => ['10.0.0.8', '1.1.1.1'],
            'empty.example.com' => [],
        ]));

        $this->expectException(UnsafeWebhookUrlException::class);

        $guard->resolve($url);
    }

    public function test_a_public_https_host_is_accepted_and_pinned(): void
    {
        $guard = new PublicHttpsEndpoint(new MapHostAddressLookup([
            'hooks.example.com' => ['1.1.1.1'],
        ]));

        $resolved = $guard->resolve('https://hooks.example.com/hook');

        $this->assertSame('hooks.example.com', $resolved->host);
        $this->assertSame(443, $resolved->port);
        $this->assertSame('1.1.1.1', $resolved->address);
        $literal = $guard->resolve('https://8.8.8.8:8443/hook');
        $this->assertSame('8.8.8.8', $literal->host);
        $this->assertSame(8443, $literal->port);
        $this->assertNull($literal->address);
        $this->assertNull($guard->resolve('https://[2606:4700:4700::1111]/hook')->address);
        $this->assertNull($guard->resolve('https://172.15.0.1/hook')->address);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function blockedUrls(): array
    {
        return [
            ['http://1.1.1.1/hook'],
            ['https://127.0.0.1/hook'],
            ['https://10.1.2.3/hook'],
            ['https://192.168.0.10/hook'],
            ['https://172.16.5.1/hook'],
            ['https://169.254.169.254/latest'],
            ['https://0.0.0.0/hook'],
            ['https://100.64.1.1/hook'],
            ['https://192.0.2.1/hook'],
            ['https://198.51.100.1/hook'],
            ['https://203.0.113.1/hook'],
            ['https://224.0.0.1/hook'],
            ['https://255.255.255.255/hook'],
            ['https://[::1]/hook'],
            ['https://[fc00::1]/hook'],
            ['https://[fe80::1]/hook'],
            ['https://[2001:db8::1]/hook'],
            ['https://[::ffff:127.0.0.1]/hook'],
            ['https://localhost/hook'],
            ['https://printer.local/hook'],
            ['https://metadata.google.internal/hook'],
            ['https://user:pass@hooks.example.com/hook'],
            ['https://2130706433/hook'],
            ['https://hooks.example.com/hook#frag'],
            ['https://hooks.example.com/hook'],
            ['https://empty.example.com/hook'],
        ];
    }
}
