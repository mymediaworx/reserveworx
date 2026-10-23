<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_platform_admin' => $this->isPlatformAdmin(),
            'memberships' => $this->whenLoaded('memberships', fn () => $this->memberships->map(fn ($membership): array => [
                'id' => $membership->id,
                'tenant_id' => $membership->tenant_id,
                'role' => $membership->role?->slug,
                'status' => $membership->status->value,
            ])->values()),
        ];
    }
}
