<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\InspectionScheduleFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A repeating inspection on one asset or one pool, due every `interval_days`
 * days or after `interval_uses` finished rentals since it was last done.
 */
class InspectionSchedule extends Model
{
    /** @use HasFactory<InspectionScheduleFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'asset_id',
        'pool_id',
        'name',
        'interval_days',
        'interval_uses',
        'last_done_at',
        'next_due_at',
    ];

    protected $casts = [
        'interval_days' => 'integer',
        'interval_uses' => 'integer',
        'last_done_at' => 'datetime',
        'next_due_at' => 'datetime',
    ];

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
