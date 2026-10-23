<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if (array_key_exists('tenant_id', $model->getAttributes())) {
                return;
            }

            $tenantId = app(TenantManager::class)->id();

            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);

                return;
            }

            throw new MissingTenantContextException('Tenant context is required to create '.$model::class.'.');
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
