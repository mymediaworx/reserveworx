<?php

namespace App\Services\CheckIn;

/**
 * Whether the booking has signed every current waiver.
 */
final class WaiverReadiness
{
    /**
     * @param  list<array{who: string, waiver: string}>  $missing
     */
    public function __construct(
        public bool $required,
        public bool $complete,
        public array $missing,
    ) {}
}
