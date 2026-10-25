<?php

namespace App\Services\Search;

final readonly class QuickSearchGroup
{
    /**
     * @param  list<QuickSearchHit>  $hits
     */
    public function __construct(
        public string $type,
        public string $label,
        public array $hits,
        public int $page,
        public int $lastPage,
        public int $total,
    ) {}

    public static function empty(string $type, string $label): self
    {
        return new self($type, $label, [], 1, 1, 0);
    }
}
