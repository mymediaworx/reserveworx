<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\LimitsStaffLocations;
use Database\Factories\LocationClosureFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LocationClosure extends Model
{
    /** @use HasFactory<LocationClosureFactory> */
    use BelongsToTenant, HasFactory, HasUlids, LimitsStaffLocations;

    protected $table = 'closures';

    protected $fillable = [
        'tenant_id',
        'location_id',
        'resource_category_id',
        'resource_type',
        'resource_id',
        'reason',
        'all_day',
        'recurs_yearly',
        'starts_on',
        'ends_on',
        'starts_local',
        'ends_local',
    ];

    protected $casts = [
        'all_day' => 'boolean',
        'recurs_yearly' => 'boolean',
        'starts_on' => 'date',
        'ends_on' => 'date',
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
