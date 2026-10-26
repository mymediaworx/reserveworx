<?php

namespace App\Services\Backup;

final readonly class RestoreReport
{
    public function __construct(
        public bool $restored,
        public string $status,
        public string $database,
        public int $statements,
    ) {}

    public static function failed(string $status, string $database = ''): self
    {
        return new self(false, $status, $database, 0);
    }
}
