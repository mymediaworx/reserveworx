<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CorporateAccount extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    /**
     * @return HasMany<PriceRule, $this>
     */
    public function priceRules(): HasMany
    {
        return $this->hasMany(PriceRule::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
