<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Plan name for the Section 2.2 hosting check.
 *
 * SiteGround does not publish a local file or command that names StartUp,
 * GrowBig, GoGeek, or Cloud. This class does not read the hostname, process
 * limits, or git, and it does not guess. Host text is discarded so a mention
 * of a plan, a path, or a secret is not copied into the report.
 */
final class HostPlanName
{
    private const MAX_BYTES = 8192;

    /**
     * @param  (Closure(): (string|false))|null  $session
     */
    public function __construct(private readonly ?Closure $session = null) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $this->sessionText();

        return [['plan_name', 'not_measured']];
    }

    private function sessionText(): string
    {
        if ($this->session === null) {
            return '';
        }

        try {
            $text = ($this->session)();
        } catch (Throwable) {
            return '';
        }

        if (! is_string($text)) {
            return '';
        }

        return strlen($text) > self::MAX_BYTES ? substr($text, 0, self::MAX_BYTES) : $text;
    }
}
