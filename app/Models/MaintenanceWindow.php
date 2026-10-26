<?php

namespace App\Models;

use App\Enums\MaintenanceKind;
use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceWindow extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id', 'asset_id', 'pool_id', 'kind', 'starts_at', 'ends_at', 'ended_at', 'reason',
    ];

    protected $attributes = [
        'kind' => 'maintenance',
    ];

    protected $casts = [
        'kind' => MaintenanceKind::class,
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /**
     * When this window stops blocking new bookings. Ending early sets
     * ended_at and leaves the planned ends_at in place.
     */
    public function blocksUntil(): CarbonImmutable
    {
        $planned = CarbonImmutable::instance($this->ends_at)->utc();

        if ($this->ended_at === null) {
            return $planned;
        }

        $ended = CarbonImmutable::instance($this->ended_at)->utc();

        return $ended->lessThan($planned) ? $ended : $planned;
    }

    /**
     * Windows whose blocking interval overlaps [start, end).
     *
     * @param  Builder<MaintenanceWindow>  $query
     * @return Builder<MaintenanceWindow>
     */
    public function scopeBlocking(Builder $query, CarbonImmutable $start, CarbonImmutable $end): Builder
    {
        return $query
            ->where('starts_at', '<', $end)
            ->where(function (Builder $query) use ($start): void {
                $query->where(function (Builder $query) use ($start): void {
                    $query->whereNull('ended_at')->where('ends_at', '>', $start);
                })->orWhere(function (Builder $query) use ($start): void {
                    $query->whereNotNull('ended_at')
                        ->whereColumn('ended_at', '<', 'ends_at')
                        ->whereColumn('ended_at', '>', 'starts_at')
                        ->where('ended_at', '>', $start);
                })->orWhere(function (Builder $query) use ($start): void {
                    $query->whereNotNull('ended_at')
                        ->whereColumn('ended_at', '>=', 'ends_at')
                        ->where('ends_at', '>', $start);
                });
            });
    }

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * @return BelongsTo<Pool, $this>
     */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(Pool::class);
    }
}
