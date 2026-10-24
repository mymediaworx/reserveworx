<?php

namespace App\Models;

use App\Enums\ResourceType;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Allocation\ConsumesInventory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceVariantResource extends Model implements ConsumesInventory
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'service_variant_id',
        'resource_category_id',
        'resource_type',
        'resource_id',
        'quantity',
        'capacity',
    ];

    protected $casts = [
        'resource_type' => ResourceType::class,
        'quantity' => 'integer',
        'capacity' => 'integer',
    ];

    /**
     * @return BelongsTo<ServiceVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ServiceVariant::class, 'service_variant_id');
    }

    /**
     * @return BelongsTo<ResourceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class, 'resource_category_id');
    }

    public function resourceType(): ResourceType
    {
        return $this->resource_type;
    }

    public function resourceId(): string
    {
        return $this->resource_id;
    }

    public function unitsFor(int $participants): int
    {
        if ($this->capacity === null || $this->capacity < 1) {
            return $this->quantity;
        }

        return (int) (ceil($participants / $this->capacity) * $this->quantity);
    }
}
