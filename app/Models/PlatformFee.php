<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PlatformFee extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'booking_id', 'payment_id', 'amount_cents', 'refunded_cents', 'stripe_fee_id', 'channel',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'refunded_cents' => 'integer',
    ];
}
