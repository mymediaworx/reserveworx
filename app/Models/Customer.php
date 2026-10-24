<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'customer_account_id',
        'anonymized_at',
        'feedback_opted_out_at',
    ];

    protected $casts = [
        'anonymized_at' => 'datetime',
        'feedback_opted_out_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

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
