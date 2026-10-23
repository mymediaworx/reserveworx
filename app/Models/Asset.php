<?php

namespace App\Models;

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
        'name',
        'status',
        'capacity',
        'is_guide',
        'prep_buffer_minutes',
        'return_buffer_minutes',
        'turnaround_minutes',
    ];

    protected $casts = [
        'status' => ResourceStatus::class,
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
}
