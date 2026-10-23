<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PriceRule extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'service_id', 'name', 'kind', 'amount_cents', 'window', 'duration_minutes', 'starts_on', 'ends_on',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'duration_minutes' => 'integer',
        'starts_on' => 'date',
        'ends_on' => 'date',
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
