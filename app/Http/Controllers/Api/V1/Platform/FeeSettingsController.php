<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateFeeSettingsRequest;
use App\Http\Resources\TenantSettingResource;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantScope;

class FeeSettingsController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function update(UpdateFeeSettingsRequest $request, Tenant $tenant): TenantSettingResource
    {
        $setting = TenantSetting::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $before = $setting->only([
            'direct_fee_bps',
            'third_party_fee_bps',
            'direct_fee_fixed_cents',
            'third_party_fee_fixed_cents',
            'direct_fee_minimum_cents',
            'third_party_fee_minimum_cents',
        ]);

        $setting->fill($request->safe()->only(array_keys($before)));
        $setting->save();

        $this->audit->log(
            action: 'platform.fees_updated',
            subject: $setting,
            properties: ['before' => $before, 'after' => $setting->only(array_keys($before))],
            tenantId: $tenant->id,
            actor: $request->user() instanceof User ? $request->user() : null,
            request: $request,
            useContextTenant: false,
        );

        return new TenantSettingResource($setting);
    }
}
