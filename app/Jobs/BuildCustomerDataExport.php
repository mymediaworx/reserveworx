<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Privacy\CustomerDataExporter;
use App\Services\Privacy\CustomerExportException;
use App\Tenancy\TenantManager;
use Throwable;

class BuildCustomerDataExport extends TenantAwareJob
{
    private ?CustomerDataExporter $exports = null;

    public function __construct(string $tenantId, public string $exportId)
    {
        parent::__construct($tenantId);
    }

    public function handle(TenantManager $tenants, ?CustomerDataExporter $exports = null): void
    {
        $this->exports = $exports;
        $previous = $tenants->get();

        try {
            parent::handle($tenants);
        } finally {
            if ($previous instanceof Tenant) {
                $tenants->set($previous);
            }
        }
    }

    protected function handleForTenant(Tenant $tenant): void
    {
        $exports = $this->exports;

        if (! $exports instanceof CustomerDataExporter) {
            throw new CustomerExportException('The export job has no writer.');
        }

        try {
            $exports->write($this->exportId);
        } catch (Throwable $exception) {
            $exports->markFailed($this->exportId);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        app(CustomerDataExporter::class)->markFailed($this->exportId);
    }
}
