<?php

namespace App\Services\Webhooks;

/**
 * Accepts only HTTPS URLs whose host is a public address.
 * A name that resolves to any private, loopback, or link-local address is refused.
 */
final class PublicHttpsEndpoint
{
    public function __construct(private readonly HostAddressLookup $lookup) {}

    public function resolve(string $url): ResolvedWebhookUrl
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > 2000 || preg_match('/[\s#\\\\@]/', $url) === 1) {
            throw UnsafeWebhookUrlException::refused();
        }

        $parts = parse_url($url);

        if (! is_array($parts)) {
            throw UnsafeWebhookUrlException::refused();
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));

        if ($scheme !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw UnsafeWebhookUrlException::refused();
        }

        $host = $parts['host'] ?? null;

        if (! is_string($host) || $host === '') {
            throw UnsafeWebhookUrlException::refused();
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        $host = strtolower($host);
        $port = $parts['port'] ?? 443;

        if ($port < 1) {
            throw UnsafeWebhookUrlException::refused();
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if ($this->ipv4Blocked($host)) {
                throw UnsafeWebhookUrlException::refused();
            }

            return new ResolvedWebhookUrl($url, $host, $port, null);
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            if ($this->ipv6Blocked($host)) {
                throw UnsafeWebhookUrlException::refused();
            }

            return new ResolvedWebhookUrl($url, $host, $port, null);
        }

        if (! $this->isDnsName($host) || $this->isBlockedName($host)) {
            throw UnsafeWebhookUrlException::refused();
        }

        $addresses = $this->lookup->addresses($host);

        if ($addresses === []) {
            throw UnsafeWebhookUrlException::refused();
        }

        foreach ($addresses as $address) {
            if ($this->addressBlocked($address)) {
                throw UnsafeWebhookUrlException::refused();
            }
        }

        return new ResolvedWebhookUrl($url, $host, $port, $addresses[0]);
    }

    private function addressBlocked(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $this->ipv4Blocked($address);
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return $this->ipv6Blocked($address);
        }

        return true;
    }

    private function ipv4Blocked(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 4) {
            return true;
        }

        $ranges = [
            ['0.0.0.0', 8],
            ['10.0.0.0', 8],
            ['100.64.0.0', 10],
            ['127.0.0.0', 8],
            ['169.254.0.0', 16],
            ['172.16.0.0', 12],
            ['192.0.0.0', 24],
            ['192.0.2.0', 24],
            ['192.168.0.0', 16],
            ['198.18.0.0', 15],
            ['198.51.100.0', 24],
            ['203.0.113.0', 24],
            ['224.0.0.0', 4],
            ['240.0.0.0', 4],
        ];

        foreach ($ranges as [$subnet, $bits]) {
            if ($this->packedInCidr($packed, $subnet, $bits)) {
                return true;
            }
        }

        return false;
    }

    private function ipv6Blocked(string $ip): bool
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return true;
        }

        $mapped = str_repeat("\0", 10)."\xff\xff";

        if (str_starts_with($packed, $mapped)) {
            $v4 = inet_ntop(substr($packed, 12));

            return ! is_string($v4) || $this->ipv4Blocked($v4);
        }

        if (str_starts_with($packed, str_repeat("\0", 12)) && substr($packed, 12) !== "\0\0\0\0") {
            $v4 = inet_ntop(substr($packed, 12));

            return ! is_string($v4) || $this->ipv4Blocked($v4);
        }

        if ($packed === str_repeat("\0", 16) || $packed === str_repeat("\0", 15)."\x01") {
            return true;
        }

        $first = ord($packed[0]);

        if (($first & 0xFE) === 0xFC || $first === 0xFF) {
            return true;
        }

        if ($first === 0xFE && (ord($packed[1]) & 0xC0) === 0x80) {
            return true;
        }

        return str_starts_with($packed, "\x20\x01\x0d\xb8");
    }

    private function packedInCidr(string $ipPacked, string $subnet, int $bits): bool
    {
        $subnetPacked = inet_pton($subnet);

        if ($subnetPacked === false || strlen($subnetPacked) !== strlen($ipPacked)) {
            return false;
        }

        $bytes = intdiv($bits, 8);

        if ($bytes > 0 && substr($ipPacked, 0, $bytes) !== substr($subnetPacked, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($ipPacked[$bytes]) & $mask) === (ord($subnetPacked[$bytes]) & $mask);
    }

    private function isDnsName(string $host): bool
    {
        return preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) === 1;
    }

    private function isBlockedName(string $host): bool
    {
        if ($host === 'localhost' || $host === 'localhost.localdomain') {
            return true;
        }

        foreach (['.localhost', '.local', '.internal', '.localdomain', '.arpa', '.lan', '.home', '.corp'] as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
