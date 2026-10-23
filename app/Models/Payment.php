<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'booking_id', 'method', 'kind', 'status', 'amount_cents', 'fee_cents',
        'stripe_checkout_session_id', 'stripe_payment_intent_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'fee_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
