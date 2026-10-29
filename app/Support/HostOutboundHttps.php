<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Outbound HTTPS for the Section 2.2 hosting check.
 *
 * Opens one verified TLS connection to the Stripe API host and one to the
 * HighLevel API host, then reads the HTTP status line. The address, the
 * response, and any error text are not returned. A failed probe stays
 * `failed` or `unreachable` and does not fail the check.
 */
final class HostOutboundHttps
{
    private const TIMEOUT_SECONDS = 3;

    /**
     * @param  (Closure(): array<string, string>)|null  $probe
     */
    public function __construct(private readonly ?Closure $probe = null) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $measured = $this->measure();

        return [
            ['stripe_tls', $this->tls($measured['stripe_tls'] ?? '')],
            ['stripe_https', $this->https($measured['stripe_https'] ?? '')],
            ['ghl_tls', $this->tls($measured['ghl_tls'] ?? '')],
            ['ghl_https', $this->https($measured['ghl_https'] ?? '')],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function measure(): array
    {
        if ($this->probe !== null) {
            try {
                return ($this->probe)();
            } catch (Throwable) {
                return [];
            }
        }

        $stripe = $this->request('api.stripe.com');
        $highlevel = $this->request('services.leadconnectorhq.com');

        return [
            'stripe_tls' => $stripe['tls'],
            'stripe_https' => $stripe['https'],
            'ghl_tls' => $highlevel['tls'],
            'ghl_https' => $highlevel['https'],
        ];
    }

    /**
     * @return array{tls: string, https: string}
     */
    private function request(string $host): array
    {
        $socket = $this->open($host);

        if ($socket === null) {
            return ['tls' => 'failed', 'https' => 'unreachable'];
        }

        $status = $this->statusCode($socket, $host);
        fclose($socket);

        return ['tls' => 'verified', 'https' => $status];
    }

    /**
     * @return resource|null
     */
    private function open(string $host): mixed
    {
        $errorNumber = 0;
        $errorText = '';

        try {
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'SNI_enabled' => true,
                    'peer_name' => $host,
                ],
            ]);
            $socket = stream_socket_client(
                'ssl://'.$host.':443',
                $errorNumber,
                $errorText,
                self::TIMEOUT_SECONDS,
                STREAM_CLIENT_CONNECT,
                $context,
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($socket)) {
            return null;
        }

        return $socket;
    }

    /**
     * @param  resource  $socket
     */
    private function statusCode($socket, string $host): string
    {
        try {
            stream_set_timeout($socket, self::TIMEOUT_SECONDS);
            $written = fwrite($socket, "GET / HTTP/1.0\r\nHost: ".$host."\r\nConnection: close\r\n\r\n");

            if ($written === false) {
                return 'unreachable';
            }

            $line = fgets($socket, 64);
        } catch (Throwable) {
            return 'unreachable';
        }

        if (! is_string($line) || preg_match('/\AHTTP\/\d(?:\.\d)? (\d{3})\b/', $line, $matches) !== 1) {
            return 'unreachable';
        }

        return $this->https($matches[1]);
    }

    private function tls(string $value): string
    {
        return $value === 'verified' ? 'verified' : 'failed';
    }

    private function https(string $value): string
    {
        if (preg_match('/\A[1-5][0-9]{2}\z/', $value) !== 1) {
            return 'unreachable';
        }

        return $value;
    }
}
