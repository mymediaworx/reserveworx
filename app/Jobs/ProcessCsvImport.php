<?php

namespace App\Jobs;

use App\Enums\ImportStatus;
use App\Models\CsvImport;
use App\Models\Tenant;
use App\Services\Import\CsvImporter;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use Throwable;

/**
 * Writes one bounded chunk of a CSV import, then queues the next chunk.
 * The database queue and a short cron max-time are what shared hosting allows.
 */
class ProcessCsvImport extends TenantAwareJob
{
    public int $tries = 3;

    public int $timeout = 20;

    public function __construct(string $tenantId, public string $importId)
    {
        parent::__construct($tenantId);
        $this->onConnection('database');
    }

    protected function handleForTenant(Tenant $tenant): void
    {
        if ($tenant->id !== $this->tenantId) {
            throw new TenantContextException('Refusing to import a file for a different workspace.');
        }

        app(CsvImporter::class)->processChunk($this->importId);
    }

    public function failed(?Throwable $exception): void
    {
        $tenants = app(TenantManager::class);
        $tenant = Tenant::query()->find($this->tenantId);

        if (! $tenant instanceof Tenant) {
            return;
        }

        $tenants->set($tenant);

        try {
            $import = CsvImport::query()->find($this->importId);

            if ($import instanceof CsvImport && $import->status === ImportStatus::Processing) {
                app(CsvImporter::class)->markFailed($import, 'The import stopped before it finished. Run it again.');
            }
        } finally {
            $tenants->forget();
        }
    }
}
