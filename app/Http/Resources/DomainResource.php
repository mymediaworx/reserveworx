<?php

namespace App\Http\Resources;

use App\Models\Domain;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Domain */
class DomainResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'hostname' => $this->hostname,
            'type' => $this->type,
            'is_primary' => $this->is_primary,
            'verified_at' => $this->verified_at?->toIso8601String(),
        ];
    }
}
