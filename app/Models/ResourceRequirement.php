<?php

namespace App\Models;

use App\Enums\ResourceType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceRequirement extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'resource_type',
        'resource_id',
        'quantity',
        'per_participants',
    ];

    protected $casts = [
        'resource_type' => ResourceType::class,
        'quantity' => 'integer',
        'per_participants' => 'integer',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function unitsFor(int $participants): int
    {
        if ($this->per_participants === null || $this->per_participants < 1) {
            return $this->quantity;
        }

        return (int) (ceil($participants / $this->per_participants) * $this->quantity);
    }
}
