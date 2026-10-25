<?php

namespace App\Services\Import;

use App\Models\User;

interface RowWriter
{
    public function prepare(string $tenantId, User $actor): void;

    /**
     * @param  array<string, string>  $values
     * @return array{errors: list<string>, notice: string}
     */
    public function inspect(array $values): array;

    /**
     * @param  array<string, string>  $values
     * @return array{outcome: 'created'|'updated'|'skipped', reason: string}
     */
    public function apply(array $values): array;
}
