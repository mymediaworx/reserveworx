<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The verified SiteGround artisan process does not stop itself
 * (max_execution_time=0). Work started by cron shares one short window
 * so a minute of schedule:run cannot grow without a bound.
 */
final class SharedHostBudget
{
    public const FLOOR_SECONDS = 10;

    public const CEILING_SECONDS = 20;

    /**
     * @param  Closure(): float  $now
     */
    public function __construct(
        public readonly int $seconds,
        private readonly float $startedAt,
        private readonly Closure $now,
    ) {}

    public static function seconds(): int
    {
        $configured = (int) config('reserveworx.queue_work_max_time');

        return max(self::FLOOR_SECONDS, min(self::CEILING_SECONDS, $configured));
    }

    public static function start(): self
    {
        $seconds = self::seconds();

        return new self($seconds, self::anchor($seconds), static fn (): float => microtime(true));
    }

    public function expired(): bool
    {
        return ($this->now)() - $this->startedAt >= $this->seconds;
    }

    private static function anchor(int $seconds): float
    {
        $key = 'shared-host-window';
        Cache::add($key, microtime(true), $seconds);
        $stored = Cache::get($key);

        if (is_int($stored) || is_float($stored)) {
            return (float) $stored;
        }

        if (is_string($stored) && is_numeric($stored)) {
            return (float) $stored;
        }

        return microtime(true);
    }
}
