<?php

namespace App\Http\Resources;

use App\Models\AddOn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AddOn */
class AddOnResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'kind' => $this->kind->value,
            'pricing' => $this->pricing->value,
            'amount_cents' => $this->amount_cents,
            'active' => $this->active,
            'max_quantity' => $this->max_quantity,
        ];
    }
}
