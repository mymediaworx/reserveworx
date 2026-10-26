<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\GhlMappingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class GhlMapping extends Model
{
    /** @use HasFactory<GhlMappingFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'location_id',
        'contact_id',
    ];
}
