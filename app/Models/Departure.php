<?php

namespace App\Models;

use App\Enums\BookingStatus;
use App\Enums\DepartureStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Departure extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'service_id',
        'location_id',
        'starts_at',
        'ends_at',
        'private',
        'seats',
        'status',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'private' => 'boolean',
        'seats' => 'integer',
        'status' => DepartureStatus::class,
    ];

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function headcount(): int
    {
        return (int) $this->bookings()
            ->whereIn('status', [
                BookingStatus::Held->value,
                BookingStatus::Confirmed->value,
                BookingStatus::PendingPayment->value,
            ])
            ->where(function ($query): void {
                $query->whereHas('allocations')
                    ->orWhereHas('holds', fn ($holds) => $holds->where('expires_at', '>', now()));
            })
            ->sum('participants');
    }
}
