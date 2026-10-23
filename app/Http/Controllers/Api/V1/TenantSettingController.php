<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateTenantSettingRequest;
use App\Http\Resources\TenantSettingResource;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class TenantSettingController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function show(Request $request): TenantSettingResource
    {
        $setting = TenantSetting::query()->firstOrFail();
        $this->authorize('view', $setting);

        return new TenantSettingResource($setting);
    }

    public function update(UpdateTenantSettingRequest $request): TenantSettingResource
    {
        $setting = TenantSetting::query()->firstOrFail();
        $before = $setting->only([
            'hold_minutes',
            'ledger_bucket_minutes',
            'fee_payer',
        ]);

        $setting->fill($request->safe()->only([
            'hold_minutes',
            'ledger_bucket_minutes',
            'fee_payer',
        ]));
        $setting->save();

        $actor = $request->user();

        $this->audit->log(
            action: 'settings.updated',
            subject: $setting,
            properties: [
                'before' => [
                    'hold_minutes' => $before['hold_minutes'],
                    'ledger_bucket_minutes' => $before['ledger_bucket_minutes'],
                    'fee_payer' => $before['fee_payer'] instanceof \BackedEnum ? $before['fee_payer']->value : $before['fee_payer'],
                ],
                'after' => [
                    'hold_minutes' => $setting->hold_minutes,
                    'ledger_bucket_minutes' => $setting->ledger_bucket_minutes,
                    'fee_payer' => $setting->fee_payer->value,
                ],
            ],
            actor: $actor instanceof User ? $actor : null,
            request: $request,
        );

        return new TenantSettingResource($setting);
    }
}
