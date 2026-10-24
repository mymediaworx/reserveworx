<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceVariant extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'name',
        'duration_minutes',
        'price_override_cents',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'price_override_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<ServiceVariantResource, $this>
     */
    public function resources(): HasMany
    {
        return $this->hasMany(ServiceVariantResource::class);
    }
}
