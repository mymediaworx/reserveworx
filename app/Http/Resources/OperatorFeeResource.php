<?php

namespace App\Http\Resources;

use App\Models\OperatorFee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OperatorFee */
class OperatorFeeResource extends JsonResource
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
            'pricing' => $this->pricing->value,
            'amount_cents' => $this->amount_cents,
            'percent_bps' => $this->percent_bps,
            'service_id' => $this->service_id,
            'location_id' => $this->location_id,
            'active' => $this->active,
        ];
    }
}
