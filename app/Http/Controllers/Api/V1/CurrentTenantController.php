<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\TenantResource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class CurrentTenantController extends Controller
{
    public function show(Request $request): TenantResource
    {
        $tenant = $request->attributes->get('tenant');
        $user = $request->user();

        if (! $tenant instanceof Tenant || ! $user instanceof User) {
            abort(404);
        }

        $this->authorize('view', $tenant);

        return new TenantResource($tenant);
    }
}
