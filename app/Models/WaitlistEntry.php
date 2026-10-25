<?php

namespace App\Models;

use App\Enums\WaitlistStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LimitsStaffLocations;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WaitlistEntry extends Model
{
    use BelongsToTenant, HasUlids, LimitsStaffLocations;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'service_variant_id',
        'departure_id',
        'booking_id',
        'location_id',
        'created_by',
        'starts_at',
        'ends_at',
        'participants',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
        'idempotency_key',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'participants' => 'integer',
        'status' => WaitlistStatus::class,
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Departure, $this>
     */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /**
     * @return BelongsTo<Booking, $this>
     */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }
}
