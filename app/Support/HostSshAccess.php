<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Inbound SSH for the Section 2.2 hosting check.
 *
 * OpenSSH sets SSH_CONNECTION or SSH_CLIENT on a process started from a login.
 * A non-empty value is a session. Anything else is not measured: this process
 * cannot see whether a new login would be accepted. The variable text, the
 * host, the port, and a key are not stored.
 */
final class HostSshAccess
{
    private const SAMPLE_BYTES = 256;

    /**
     * @var list<string>
     */
    private const NAMES = [
        'SSH_CONNECTION',
        'SSH_CLIENT',
    ];

    /**
     * @param  (Closure(): mixed)|null  $environment
     */
    public function __construct(private readonly ?Closure $environment = null) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        return [['ssh', $this->session() ? 'session' : 'not_measured']];
    }

    private function session(): bool
    {
        $environment = $this->environment();

        foreach (self::NAMES as $name) {
            if (! array_key_exists($name, $environment)) {
                continue;
            }

            $value = $environment[$name];

            if (! is_string($value) || $value === '') {
                continue;
            }

            if (trim(substr($value, 0, self::SAMPLE_BYTES)) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function environment(): array
    {
        if ($this->environment !== null) {
            try {
                $read = ($this->environment)();
            } catch (Throwable) {
                return [];
            }

            return is_array($read) ? $read : [];
        }

        $values = [];

        foreach (self::NAMES as $name) {
            $value = getenv($name);

            if (is_string($value)) {
                $values[$name] = $value;
            }
        }

        return $values;
    }
}
