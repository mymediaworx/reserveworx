<?php

namespace App\Services\Search;

final readonly class QuickSearchHit
{
    public function __construct(
        public string $type,
        public string $id,
        public string $title,
        public string $meta,
        public string $href,
    ) {}
}
