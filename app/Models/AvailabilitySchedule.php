<?php

namespace App\Models;

use App\Enums\ScheduleOwnerType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AvailabilitySchedule extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'owner_type',
        'owner_id',
        'weekday',
        'opens_local',
        'closes_local',
    ];

    protected $casts = [
        'owner_type' => ScheduleOwnerType::class,
        'weekday' => 'integer',
    ];
}
