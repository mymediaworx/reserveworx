<?php

namespace App\Http\Resources;

use App\Models\TenantBranding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantBranding */
class TenantBrandingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'display_name' => $this->display_name,
            'logo_url' => $this->publicLogoUrl($request),
            'primary_color' => $this->primary_color,
            'accent_color' => $this->accent_color,
            'support_email' => $this->support_email,
            'support_phone' => $this->support_phone,
        ];
    }
}
