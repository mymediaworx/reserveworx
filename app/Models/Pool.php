<?php

namespace App\Models;

use App\Enums\ResourceFlag;
use App\Enums\ResourceStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LimitsStaffLocations;
use Database\Factories\PoolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class Pool extends Model
{
    /** @use HasFactory<PoolFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LimitsStaffLocations;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'resource_category_id',
        'name',
        'quantity',
        'status',
        'condition_flag',
        'prep_buffer_minutes',
        'return_buffer_minutes',
        'turnaround_minutes',
    ];

    protected static function booted(): void
    {
        static::saving(function (Pool $pool): void {
            if ($pool->status !== ResourceStatus::Retired) {
                return;
            }

            throw ValidationException::withMessages([
                'status' => 'A pool cannot be retired.',
            ]);
        });
    }

    protected $casts = [
        'status' => ResourceStatus::class,
        'condition_flag' => ResourceFlag::class,
        'quantity' => 'integer',
        'prep_buffer_minutes' => 'integer',
        'return_buffer_minutes' => 'integer',
        'turnaround_minutes' => 'integer',
    ];

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return BelongsTo<ResourceCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ResourceCategory::class, 'resource_category_id');
    }
}
