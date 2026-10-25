<?php

namespace App\Models;

use App\Enums\LateFeeDecision;
use App\Enums\ReturnCondition;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalReturn extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'rental_checkout_id',
        'asset_id',
        'pool_id',
        'condition',
        'notes',
        'minutes_late',
        'late_fee_cents',
        'late_fee_decision',
        'late_fee_reason',
        'recorded_by',
    ];

    protected $casts = [
        'condition' => ReturnCondition::class,
        'minutes_late' => 'integer',
        'late_fee_cents' => 'integer',
        'late_fee_decision' => LateFeeDecision::class,
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<Pool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }

    /**
     * @return BelongsTo<RentalCheckout, $this>
     */
    public function checkout(): BelongsTo
    {
        return $this->belongsTo(RentalCheckout::class, 'rental_checkout_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
