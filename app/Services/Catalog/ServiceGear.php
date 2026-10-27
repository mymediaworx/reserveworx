<?php

namespace App\Services\Catalog;

use App\Models\ResourceRequirement;
use App\Models\Service;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

final class ServiceGear
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function remove(Service $service, ResourceRequirement $requirement, ?Request $request = null): void
    {
        if ($requirement->service_id !== $service->id || $requirement->tenant_id !== $service->tenant_id) {
            abort(404);
        }

        $this->audit->log('service.requirement_removed', $requirement, [
            'service_id' => $service->id,
            'resource_type' => $requirement->resource_type->value,
            'resource_id' => $requirement->resource_id,
            'quantity' => $requirement->quantity,
        ], request: $request);

        $requirement->delete();
    }
}
