<?php

namespace App\Models;

use App\Enums\LocationStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LimitsStaffLocations;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LimitsStaffLocations, SoftDeletes;

    public static function staffLocationColumn(): string
    {
        return 'id';
    }

    protected $fillable = [
        'tenant_id',
        'name',
        'timezone',
        'status',
        'booking_fee_taxable',
        'delivered_from_fleet',
        'walk_ins_enabled',
    ];

    protected $casts = [
        'status' => LocationStatus::class,
        'booking_fee_taxable' => 'boolean',
        'delivered_from_fleet' => 'boolean',
        'walk_ins_enabled' => 'boolean',
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

    /**
     * @return HasMany<LocationFleetReserve, $this>
     */
    public function fleetReserves(): HasMany
    {
        return $this->hasMany(LocationFleetReserve::class);
    }
}
