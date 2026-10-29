<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Inbound webhook limits for the Section 2.2 hosting check.
 *
 * This process cannot see a host WAF or a host rate rule. An HTTP status,
 * including 202 from a bot check, is not a measurement. The reading is
 * discarded so a response, an address, and a rule name are not copied.
 */
final class InboundWebhookLimits
{
    private const MAX_BYTES = 8192;

    /**
     * @param  (Closure(): (string|false))|null  $reading
     */
    public function __construct(private readonly ?Closure $reading = null) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $this->readingText();

        return [
            ['inbound_webhook_waf', 'not_measured'],
            ['inbound_webhook_rate_rules', 'not_measured'],
        ];
    }

    private function readingText(): string
    {
        if ($this->reading === null) {
            return '';
        }

        try {
            $text = ($this->reading)();
        } catch (Throwable) {
            return '';
        }

        if (! is_string($text)) {
            return '';
        }

        return strlen($text) > self::MAX_BYTES ? substr($text, 0, self::MAX_BYTES) : $text;
    }
}
