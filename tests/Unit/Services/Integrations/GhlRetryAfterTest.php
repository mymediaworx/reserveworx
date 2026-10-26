<?php

namespace Tests\Unit\Services\Integrations;

use App\Services\Integrations\GhlRateLimitPause;
use App\Services\Integrations\GhlRequestFailed;
use PHPUnit\Framework\TestCase;

class GhlRetryAfterTest extends TestCase
{
    private GhlRateLimitPause $pause;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pause = new GhlRateLimitPause;
    }

    public function test_retry_after_seconds_are_clamped(): void
    {
        $this->assertSame(120, $this->wait('120'));
        $this->assertSame(1, $this->wait('0'));
        $this->assertSame(10800, $this->wait('999999'));
    }

    public function test_an_http_date_is_the_seconds_until_that_instant(): void
    {
        $now = 1_700_000_000;
        $later = gmdate('D, d M Y H:i:s', $now + 90).' GMT';
        $earlier = gmdate('D, d M Y H:i:s', $now - 30).' GMT';

        $this->assertSame(90, $this->wait($later, $now));
        $this->assertSame(1, $this->wait($earlier, $now));
        $this->assertSame(60, $this->wait('not-a-date', $now));
    }

    public function test_a_burst_interval_is_used_only_when_the_remaining_count_is_zero(): void
    {
        $blocked = $this->failed(null, '0', '10000');
        $open = $this->failed(null, '4', '10000');
        $daily = new GhlRequestFailed(
            httpStatus: 429,
            kind: 'upsert',
            dailyRemaining: '0',
            intervalMilliseconds: '10000',
        );

        $this->assertSame(10, $this->pause->seconds($blocked, 1, [60, 300]));
        $this->assertSame(60, $this->pause->seconds($open, 1, [60, 300]));
        $this->assertSame(60, $this->pause->seconds($daily, 1, [60, 300]));
        $this->assertSame(300, $this->pause->seconds($open, 2, [60, 300]));
        $this->assertSame(300, $this->pause->seconds($open, 9, [60, 300]));
    }

    public function test_retry_after_wins_over_the_burst_interval(): void
    {
        $exception = $this->failed('45', '0', '10000');

        $this->assertSame(45, $this->pause->seconds($exception, 1, [60]));
    }

    public function test_eight_waits_then_stop_and_other_errors_are_not_a_rate_limit(): void
    {
        $limited = $this->failed('10');
        $unauthorized = new GhlRequestFailed(401, 'upsert');

        $this->assertNull($this->pause->decide($unauthorized, 0, [60]));
        $this->assertFalse($unauthorized->isRateLimited());
        $waiting = $this->pause->decide($limited, 7, [60]);
        $stopped = $this->pause->decide($limited, 8, [60]);

        $this->assertNotNull($waiting);
        $this->assertFalse($waiting['stopped']);
        $this->assertSame(10, $waiting['seconds']);
        $this->assertNotNull($stopped);
        $this->assertTrue($stopped['stopped']);
        $this->assertSame('GoHighLevel upsert returned HTTP 429. Waiting 10 seconds before the next try.', $this->pause->waitingError($limited, 10));
    }

    private function wait(?string $retryAfter, ?int $now = null): int
    {
        return $this->pause->seconds($this->failed($retryAfter), 1, [60, 300, 900, 3600, 10800], $now);
    }

    private function failed(?string $retryAfter, ?string $remaining = null, ?string $interval = null): GhlRequestFailed
    {
        return new GhlRequestFailed(
            httpStatus: 429,
            kind: 'upsert',
            retryAfter: $retryAfter,
            rateLimitRemaining: $remaining,
            intervalMilliseconds: $interval,
        );
    }
}
