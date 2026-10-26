<?php

namespace App\Models;

use App\Enums\CancellationPolicyKind;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CancellationPolicyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CancellationPolicy extends Model
{
    /** @use HasFactory<CancellationPolicyFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'kind',
        'lead_minutes',
        'refund_percent',
    ];

    protected $casts = [
        'kind' => CancellationPolicyKind::class,
        'lead_minutes' => 'integer',
        'refund_percent' => 'integer',
    ];
}
