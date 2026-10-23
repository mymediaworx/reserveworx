<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    use HasUlids;

    protected $fillable = [
        'slug',
        'name',
    ];
}
