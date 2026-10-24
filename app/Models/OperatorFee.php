<?php

namespace App\Models;

use App\Enums\OperatorFeePricing;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\OperatorFeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperatorFee extends Model
{
    /** @use HasFactory<OperatorFeeFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'location_id',
        'name',
        'description',
        'pricing',
        'amount_cents',
        'percent_bps',
        'active',
    ];

    protected $casts = [
        'pricing' => OperatorFeePricing::class,
        'amount_cents' => 'integer',
        'percent_bps' => 'integer',
        'active' => 'boolean',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<BookingOperatorFee, $this>
     */
    public function bookingLines(): HasMany
    {
        return $this->hasMany(BookingOperatorFee::class);
    }
}
