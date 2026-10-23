<?php

namespace App\Http\Resources;

use App\Models\Pool;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pool */
class PoolResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'location_id' => $this->location_id,
            'resource_category_id' => $this->resource_category_id,
            'name' => $this->name,
            'quantity' => $this->quantity,
            'status' => $this->status->value,
            'prep_buffer_minutes' => $this->prep_buffer_minutes,
            'return_buffer_minutes' => $this->return_buffer_minutes,
            'turnaround_minutes' => $this->turnaround_minutes,
        ];
    }
}
