<?php

namespace App\Http\Resources;

use App\Models\ServiceVariant;
use App\Models\ServiceVariantResource as VariantResourceRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceVariant */
class ServiceVariantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'name' => $this->name,
            'duration_minutes' => $this->duration_minutes,
            'price_override_cents' => $this->price_override_cents,
            'resources' => $this->resources->map(fn (VariantResourceRow $row): array => [
                'id' => $row->id,
                'resource_type' => $row->resource_type->value,
                'resource_category_id' => $row->resource_category_id,
                'resource_id' => $row->resource_id,
                'quantity' => $row->quantity,
                'capacity' => $row->capacity,
            ])->all(),
        ];
    }
}
