<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportSummary extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'summary_date',
        'available_resource_minutes',
        'allocated_resource_minutes',
        'utilization_bps',
        'bookings_created',
        'bookings_converted',
        'conversion_bps',
        'average_order_cents',
        'gross_cents',
        'revenue_per_resource_hour_cents',
    ];

    protected $casts = [
        'summary_date' => 'date',
        'available_resource_minutes' => 'integer',
        'allocated_resource_minutes' => 'integer',
        'utilization_bps' => 'integer',
        'bookings_created' => 'integer',
        'bookings_converted' => 'integer',
        'conversion_bps' => 'integer',
        'average_order_cents' => 'integer',
        'gross_cents' => 'integer',
        'revenue_per_resource_hour_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
