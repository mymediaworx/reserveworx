<?php

namespace App\Models;

use App\Enums\ServiceKind;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'name',
        'kind',
        'min_participants',
        'max_participants',
        'waitlist_enabled',
    ];

    protected $casts = [
        'kind' => ServiceKind::class,
        'min_participants' => 'integer',
        'max_participants' => 'integer',
        'waitlist_enabled' => 'boolean',
    ];

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<ResourceRequirement, $this>
     */
    public function requirements(): HasMany
    {
        return $this->hasMany(ResourceRequirement::class);
    }

    /**
     * @return HasMany<ServiceDuration, $this>
     */
    public function durations(): HasMany
    {
        return $this->hasMany(ServiceDuration::class);
    }

    /**
     * @return HasMany<ServiceVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ServiceVariant::class);
    }
}
