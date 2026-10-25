<?php

namespace App\Models;

use App\Enums\ResourceFlag;
use App\Enums\ResourceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AssetFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    /** @use HasFactory<AssetFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'location_id',
        'resource_category_id',
        'replacement_asset_id',
        'name',
        'status',
        'condition_flag',
        'capacity',
        'is_guide',
        'prep_buffer_minutes',
        'return_buffer_minutes',
        'turnaround_minutes',
    ];

    protected static function booted(): void
    {
        static::saving(function (Asset $asset): void {
            if (! $asset->exists || ! $asset->isDirty('status') || $asset->status === ResourceStatus::Retired) {
                return;
            }

            $original = $asset->getOriginal('status');

            if ($original === ResourceStatus::Retired || $original === ResourceStatus::Retired->value) {
                $asset->replacement_asset_id = null;
            }
        });
    }

    protected $casts = [
        'status' => ResourceStatus::class,
        'condition_flag' => ResourceFlag::class,
        'capacity' => 'integer',
        'is_guide' => 'boolean',
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

    /**
     * @return BelongsTo<Asset, $this>
     */
    public function replacement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replacement_asset_id');
    }
}
