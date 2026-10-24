<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class CustomerAccount extends Authenticatable
{
    use BelongsToTenant, HasApiTokens, HasUlids, Notifiable;

    protected $fillable = [
        'tenant_id',
        'email',
        'name',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    protected static function booted(): void
    {
        static::saving(function (CustomerAccount $account): void {
            $account->email = strtolower($account->email);
        });
    }

    /**
     * Guest customer rows in this workspace that this verified email owns.
     *
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    /**
     * @return HasMany<CustomerSignInToken, $this>
     */
    public function signInTokens(): HasMany
    {
        return $this->hasMany(CustomerSignInToken::class);
    }
}
