<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StripeAccount extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'stripe_account_id', 'card_payments_status', 'livemode',
    ];

    protected $casts = [
        'livemode' => 'boolean',
    ];

    public function canCharge(): bool
    {
        return $this->card_payments_status === 'active';
    }
}
