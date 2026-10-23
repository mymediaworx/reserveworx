<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class IntegrationOutbox extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'integration_outbox';

    protected $fillable = [
        'tenant_id',
        'destination',
        'kind',
        'dedupe_key',
        'payload',
        'status',
        'attempts',
        'available_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'datetime',
    ];
}
