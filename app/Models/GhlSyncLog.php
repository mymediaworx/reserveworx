<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\GhlSyncLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GhlSyncLog extends Model
{
    /** @use HasFactory<GhlSyncLogFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'booking_id',
        'payment_id',
        'kind',
        'attempt',
        'status',
        'http_status',
        'error',
        'location_id',
    ];

    protected $casts = [
        'attempt' => 'integer',
        'http_status' => 'integer',
    ];
}
