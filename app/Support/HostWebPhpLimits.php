<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Web SAPI memory and execution limits for the Section 2.2 hosting check.
 *
 * This process is the artisan CLI. Its `memory_limit` and `max_execution_time`
 * are not the web request limits. Site Tools selects the site PHP separately,
 * and this class does not read that selection, a CGI binary, or a web
 * phpinfo. Host text is discarded so a probe value, a path, or a secret is
 * not copied into the report.
 */
final class HostWebPhpLimits
{
    private const MAX_BYTES = 8192;

    /**
     * @param  (Closure(): (string|false))|null  $probe
     */
    public function __construct(private readonly ?Closure $probe = null) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $this->probeText();

        return [
            ['web_memory_limit', 'not_measured'],
            ['web_max_execution_time', 'not_measured'],
        ];
    }

    private function probeText(): string
    {
        if ($this->probe === null) {
            return '';
        }

        try {
            $text = ($this->probe)();
        } catch (Throwable) {
            return '';
        }

        if (! is_string($text)) {
            return '';
        }

        return strlen($text) > self::MAX_BYTES ? substr($text, 0, self::MAX_BYTES) : $text;
    }
}
