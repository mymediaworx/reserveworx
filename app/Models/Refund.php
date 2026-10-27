<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'payment_id',
        'reason',
        'rules_version',
        'customer_refund_cents',
        'customer_fee_refund_cents',
        'application_fee_refund_cents',
        'refund_subtotal_cents',
        'refund_tax_cents',
        'stripe_refund_id',
        'stripe_fee_refund_id',
        'idempotency_key',
        'payload_hash',
        'actor_user_id',
    ];

    protected $casts = [
        'customer_refund_cents' => 'integer',
        'customer_fee_refund_cents' => 'integer',
        'application_fee_refund_cents' => 'integer',
        'refund_subtotal_cents' => 'integer',
        'refund_tax_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
