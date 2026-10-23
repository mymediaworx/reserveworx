<?php

namespace App\Services\Allocation;

use Carbon\CarbonImmutable;

class BucketPlanner
{
    /**
     * Epoch-aligned bucket starts, in UTC unix seconds, covered by the half-open interval.
     *
     * @return list<int>
     */
    public function covering(CarbonImmutable $startUtc, CarbonImmutable $endUtc, int $bucketMinutes): array
    {
        $seconds = $bucketMinutes * 60;
        $start = $startUtc->utc()->getTimestamp();
        $end = $endUtc->utc()->getTimestamp();

        if ($seconds < 1 || $end <= $start) {
            return [];
        }

        $buckets = [];

        for ($cursor = intdiv($start, $seconds) * $seconds; $cursor < $end; $cursor += $seconds) {
            $buckets[] = $cursor;
        }

        return $buckets;
    }
}
