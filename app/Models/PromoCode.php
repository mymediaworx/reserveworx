<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'code', 'percent_off', 'amount_off_cents', 'starts_on', 'ends_on',
        'usage_limit', 'redeemed_count', 'active',
    ];

    protected $casts = [
        'percent_off' => 'integer',
        'amount_off_cents' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
        'usage_limit' => 'integer',
        'redeemed_count' => 'integer',
        'active' => 'boolean',
    ];
}
