<?php

namespace App\Models;

use App\Enums\ResourceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FleetItemFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FleetItem extends Model
{
    /** @use HasFactory<FleetItemFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'quantity',
        'status',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'status' => ResourceStatus::class,
    ];

    /**
     * @return HasMany<LocationFleetReserve, $this>
     */
    public function reserves(): HasMany
    {
        return $this->hasMany(LocationFleetReserve::class);
    }
}
