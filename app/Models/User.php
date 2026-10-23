<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->email = strtolower($user->email);
        });
    }

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Memberships are the user's own rows across workspaces, so they are not tenant-scoped.
     *
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class)->withoutGlobalScope(TenantScope::class);
    }

    /**
     * @return HasOne<PlatformAdministrator, $this>
     */
    public function platformAdministrator(): HasOne
    {
        return $this->hasOne(PlatformAdministrator::class);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->platformAdministrator()->exists();
    }
}
