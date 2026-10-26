<?php

namespace App\Models;

use App\Enums\DepartureStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LimitsStaffLocations;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Departure extends Model
{
    use BelongsToTenant, HasUlids, LimitsStaffLocations;

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
        return (int) $this->bookings()->occupyingSeats()->sum('participants');
    }

    /**
     * Seat totals for many departures, using the same rules as headcount().
     *
     * @param  Collection<int, Departure>  $departures
     * @return array<string, int>
     */
    public static function headcountsFor(Collection $departures): array
    {
        $ids = $departures->pluck('id')->filter(fn (mixed $id): bool => is_string($id) && $id !== '')->values()->all();

        if ($ids === []) {
            return [];
        }

        $rows = Booking::query()
            ->occupyingSeats()
            ->whereIn('departure_id', $ids)
            ->groupBy('departure_id')
            ->selectRaw('departure_id, SUM(participants) as seat_total')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $id = $row->getAttribute('departure_id');

            if (is_string($id) && $id !== '') {
                $counts[$id] = (int) $row->getAttribute('seat_total');
            }
        }

        return $counts;
    }
}
