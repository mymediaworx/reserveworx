<?php

namespace App\Support;

use Closure;
use Throwable;

/**
 * Soft process limits for the Section 2.2 hosting check.
 *
 * Linux writes `/proc/self/limits` as a 25-character name, one space, a
 * 20-character soft limit, one space, then the hard limit. This class reads
 * only the soft field for processes, open files, resident set, and address
 * space. A whole number or `unlimited` is kept. Anything else is `unread`.
 * The file text and the path are not returned. Long-running processes are
 * not observed.
 */
final class HostProcessLimits
{
    private const PATH = '/proc/self/limits';

    private const MAX_BYTES = 8192;

    /**
     * @var array<string, string>
     */
    private const ROWS = [
        'max_user_processes' => 'Max processes',
        'open_files' => 'Max open files',
        'max_memory_bytes' => 'Max resident set',
        'address_space_bytes' => 'Max address space',
    ];

    /**
     * @param  (Closure(): (string|false))|null  $reader
     */
    public function __construct(private readonly ?Closure $reader = null) {}

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function facts(): array
    {
        $text = $this->read();
        $facts = [];

        foreach (self::ROWS as $key => $label) {
            $facts[] = [$key, $text === false ? 'unread' : $this->softValue($text, $label)];
        }

        $facts[] = ['long_running_processes', 'not_measured'];

        return $facts;
    }

    private function read(): string|false
    {
        if ($this->reader !== null) {
            try {
                $text = ($this->reader)();
            } catch (Throwable) {
                return false;
            }

            if (! is_string($text)) {
                return false;
            }

            return strlen($text) > self::MAX_BYTES ? substr($text, 0, self::MAX_BYTES) : $text;
        }

        if (! is_file(self::PATH) || ! is_readable(self::PATH)) {
            return false;
        }

        try {
            $text = file_get_contents(self::PATH);
        } catch (Throwable) {
            return false;
        }

        if (! is_string($text)) {
            return false;
        }

        return strlen($text) > self::MAX_BYTES ? substr($text, 0, self::MAX_BYTES) : $text;
    }

    private function softValue(string $text, string $label): string
    {
        $lines = preg_split("/\n/", str_replace("\r", '', $text));

        if ($lines === false) {
            return 'unread';
        }

        foreach ($lines as $line) {
            if (strlen($line) < 46 || rtrim(substr($line, 0, 25)) !== $label) {
                continue;
            }

            $soft = trim(substr($line, 26, 20));

            if ($soft === 'unlimited' || preg_match('/^\d+$/', $soft) === 1) {
                return $soft;
            }

            return 'unread';
        }

        return 'unread';
    }
}
