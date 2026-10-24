<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class StaffEntryToken extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
