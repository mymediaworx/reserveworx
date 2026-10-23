<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class CapacityLedger extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'capacity_ledger';

    protected $fillable = [
        'tenant_id',
        'pool_id',
        'bucket_start_utc',
        'allocated_units',
        'held_units',
    ];

    protected $casts = [
        'bucket_start_utc' => 'datetime',
        'allocated_units' => 'integer',
        'held_units' => 'integer',
    ];
}
