<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class TenantDataExport extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'disk',
        'path',
        'bytes',
        'row_counts',
        'completed_at',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'row_counts' => 'array',
        'completed_at' => 'datetime',
    ];
}
