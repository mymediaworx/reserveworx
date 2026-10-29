<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Custom domains and SSL for the Section 2.2 hosting check.
 *
 * SiteGround's domain tool, wildcard DNS, and a wildcard certificate are not
 * visible here. Those facts stay not_measured. The measured fact is only
 * whether APP_URL uses https. The host, user, path, and query are not
 * printed. This class does not query DNS or open a TLS connection.
 */
final class HostDomainSupport
{
    private const MAX_BYTES = 2048;

    /**
     * @param  (Closure(): (string|false))|null  $note
     * @param  (Closure(): (string|false))|null  $appUrl
     */
    public function __construct(
        private readonly ?Closure $note = null,
        private readonly ?Closure $appUrl = null,
    ) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $this->noteText();

        return [
            ['custom_domains', 'not_measured'],
            ['wildcard_subdomains', 'not_measured'],
            ['wildcard_ssl', 'not_measured'],
            ['app_url_https', $this->httpsFlag($this->url())],
        ];
    }

    private function noteText(): string
    {
        if ($this->note === null) {
            return '';
        }

        try {
            $text = ($this->note)();
        } catch (Throwable) {
            return '';
        }

        if (! is_string($text)) {
            return '';
        }

        return strlen($text) > self::MAX_BYTES ? substr($text, 0, self::MAX_BYTES) : $text;
    }

    private function url(): string
    {
        if ($this->appUrl !== null) {
            try {
                $url = ($this->appUrl)();
            } catch (Throwable) {
                return '';
            }

            return is_string($url) ? $url : '';
        }

        $url = config('app.url');

        return is_string($url) ? $url : '';
    }

    private function httpsFlag(string $url): string
    {
        $url = trim($url);

        if ($url === '' || strlen($url) > self::MAX_BYTES || preg_match('/[\r\n]/', $url) === 1) {
            return 'unparsed';
        }

        if (preg_match('#\Ahttps://[^/\s]+#i', $url) === 1) {
            return 'yes';
        }

        if (preg_match('#\Ahttp://[^/\s]+#i', $url) === 1) {
            return 'no';
        }

        return 'unparsed';
    }
}
