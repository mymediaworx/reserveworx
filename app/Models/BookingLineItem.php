<?php

namespace App\Models;

use App\Enums\ResourceType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class BookingLineItem extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'resource_type',
        'resource_id',
        'quantity',
        'buffered_starts_at',
        'buffered_ends_at',
        'description',
    ];

    protected $casts = [
        'resource_type' => ResourceType::class,
        'quantity' => 'integer',
        'buffered_starts_at' => 'datetime',
        'buffered_ends_at' => 'datetime',
    ];
}
