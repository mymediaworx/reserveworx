<?php

namespace App\Models;

use App\Enums\DurationUnit;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceDuration extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'name',
        'quantity',
        'unit',
        'duration_minutes',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit' => DurationUnit::class,
        'duration_minutes' => 'integer',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
