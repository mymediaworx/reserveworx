<?php

namespace App\Services\Integrations;

/**
 * How long a GoHighLevel 429 waits before the next try.
 * The wait does not use one of the five failure tries.
 */
final class GhlRateLimitPause
{
    public const MAX_WAITS = 8;

    public const MIN_SECONDS = 1;

    public const MAX_SECONDS = 10800;

    public const STOPPED = 'GoHighLevel rate limit lasted through the automatic waits.';

    /**
     * @param  list<int>  $fallbackBackoff
     * @return array{stopped: bool, seconds: int}|null
     */
    public function decide(GhlRequestFailed $exception, int $waitsSoFar, array $fallbackBackoff, ?int $now = null): ?array
    {
        if (! $exception->isRateLimited()) {
            return null;
        }

        if ($waitsSoFar >= self::MAX_WAITS) {
            return ['stopped' => true, 'seconds' => 0];
        }

        return [
            'stopped' => false,
            'seconds' => $this->seconds($exception, $waitsSoFar + 1, $fallbackBackoff, $now),
        ];
    }

    public function waitingError(GhlRequestFailed $exception, int $seconds): string
    {
        return $exception->getMessage().' Waiting '.$seconds.' seconds before the next try.';
    }

    /**
     * @param  list<int>  $fallbackBackoff
     */
    public function seconds(GhlRequestFailed $exception, int $waitNumber, array $fallbackBackoff, ?int $now = null): int
    {
        $parsed = $this->retryAfter($exception->retryAfter, $now ?? time());

        if ($parsed === null) {
            $parsed = $this->burstInterval($exception);
        }

        if ($parsed === null) {
            $parsed = $this->fallback($waitNumber, $fallbackBackoff);
        }

        return max(self::MIN_SECONDS, min(self::MAX_SECONDS, $parsed));
    }

    private function retryAfter(?string $value, int $now): ?int
    {
        if ($value === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return $timestamp - $now;
    }

    private function burstInterval(GhlRequestFailed $exception): ?int
    {
        if ($exception->rateLimitRemaining !== '0' || $exception->intervalMilliseconds === null) {
            return null;
        }

        if (preg_match('/^\d+$/', $exception->intervalMilliseconds) !== 1) {
            return null;
        }

        return (int) ceil(((int) $exception->intervalMilliseconds) / 1000);
    }

    /**
     * @param  list<int>  $fallbackBackoff
     */
    private function fallback(int $waitNumber, array $fallbackBackoff): int
    {
        if ($fallbackBackoff === []) {
            return self::MIN_SECONDS;
        }

        $index = max(0, $waitNumber - 1);
        $last = $fallbackBackoff[array_key_last($fallbackBackoff)];

        return $fallbackBackoff[$index] ?? $last;
    }
}
