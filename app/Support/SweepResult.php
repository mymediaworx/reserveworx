<?php

namespace App\Support;

final readonly class SweepResult
{
    public function __construct(
        public int $visited,
        public bool $deferred,
    ) {}
}
