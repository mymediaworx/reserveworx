<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    /** @use HasFactory<CustomerFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'email',
        'phone',
        'anonymized_at',
    ];

    protected $casts = [
        'anonymized_at' => 'datetime',
    ];

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * Named for nested route binding of `{customer}/exports/{export}`.
     *
     * @return HasMany<CustomerDataExport, $this>
     */
    public function exports(): HasMany
    {
        return $this->hasMany(CustomerDataExport::class);
    }
}
