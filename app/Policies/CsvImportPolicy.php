<?php

namespace App\Policies;

use App\Models\CsvImport;
use App\Models\User;
use App\Services\Import\ImportAccess;
use App\Tenancy\TenantManager;

class CsvImportPolicy
{
    public function __construct(private readonly ImportAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->access->allows($user);
    }

    public function view(User $user, CsvImport $import): bool
    {
        return $this->sameTenant($import) && $this->access->allows($user);
    }

    public function create(User $user): bool
    {
        return $this->access->allows($user);
    }

    public function update(User $user, CsvImport $import): bool
    {
        return $this->view($user, $import);
    }

    private function sameTenant(CsvImport $import): bool
    {
        return app(TenantManager::class)->id() === $import->tenant_id;
    }
}
