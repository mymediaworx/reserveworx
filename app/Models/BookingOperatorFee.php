<?php

namespace App\Models;

use App\Enums\OperatorFeePricing;
use App\Enums\OperatorFeeScope;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\BookingOperatorFeeFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingOperatorFee extends Model
{
    /** @use HasFactory<BookingOperatorFeeFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'operator_fee_id',
        'name',
        'scope',
        'pricing',
        'amount_cents',
        'percent_bps',
        'quantity',
        'total_cents',
    ];

    protected $casts = [
        'scope' => OperatorFeeScope::class,
        'pricing' => OperatorFeePricing::class,
        'amount_cents' => 'integer',
        'percent_bps' => 'integer',
        'quantity' => 'integer',
        'total_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /**
     * @return BelongsTo<OperatorFee, $this>
     */
    public function operatorFee(): BelongsTo
    {
        return $this->belongsTo(OperatorFee::class);
    }
}
