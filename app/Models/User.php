<?php

namespace App\Models;

use App\Tenancy\TenantScope;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property Carbon|null $password_set_at
 * @property Carbon|null $email_verified_at
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected static function booted(): void
    {
        static::saving(function (User $user): void {
            $user->email = strtolower($user->email);
        });

        static::creating(function (User $user): void {
            if (! array_key_exists('password_set_at', $user->getAttributes())) {
                $user->password_set_at = now();
            }
        });
    }

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'password_set_at' => 'datetime',
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
