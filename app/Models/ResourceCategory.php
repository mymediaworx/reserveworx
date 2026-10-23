<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ResourceCategoryFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceCategory extends Model
{
    /** @use HasFactory<ResourceCategoryFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'slug',
    ];
}
