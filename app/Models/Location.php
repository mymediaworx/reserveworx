<?php

namespace App\Models;

use App\Enums\LocationStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'timezone',
        'status',
    ];

    protected $casts = [
        'status' => LocationStatus::class,
    ];

    /**
     * @return HasMany<Asset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * @return HasMany<Pool, $this>
     */
    public function pools(): HasMany
    {
        return $this->hasMany(Pool::class);
    }

    /**
     * @return HasMany<LocationTaxRule, $this>
     */
    public function taxRules(): HasMany
    {
        return $this->hasMany(LocationTaxRule::class);
    }
}
