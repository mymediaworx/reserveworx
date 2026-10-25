<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MembershipFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property MembershipStatus $status
 * @property string|null $invite_token_hash
 * @property Carbon|null $invite_expires_at
 * @property string $tenant_id
 * @property string $user_id
 * @property string $role_id
 * @property bool $locations_restricted
 */
class Membership extends Model
{
    /** @use HasFactory<MembershipFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'role_id',
        'status',
        'locations_restricted',
        'invite_token_hash',
        'invite_expires_at',
    ];

    protected $hidden = [
        'invite_token_hash',
    ];

    protected $casts = [
        'status' => MembershipStatus::class,
        'locations_restricted' => 'boolean',
        'invite_expires_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * @return HasMany<MembershipLocation, $this>
     */
    public function assignedLocations(): HasMany
    {
        return $this->hasMany(MembershipLocation::class);
    }
}
