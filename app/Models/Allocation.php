<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Allocation extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'asset_id',
        'pool_id',
        'quantity',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];
}
