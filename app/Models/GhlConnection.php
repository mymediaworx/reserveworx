<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\GhlConnectionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GhlConnection extends Model
{
    /** @use HasFactory<GhlConnectionFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'private_integration_token',
    ];

    protected $hidden = [
        'private_integration_token',
    ];

    protected $casts = [
        'private_integration_token' => 'encrypted',
    ];
}
