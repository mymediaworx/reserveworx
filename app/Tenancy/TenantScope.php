<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $manager = app(TenantManager::class);

        if ($manager->isBypassed()) {
            return;
        }

        $tenantId = $manager->id();

        if ($tenantId === null) {
            $builder->whereRaw('0 = 1');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
