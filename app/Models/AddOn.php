<?php

namespace App\Models;

use App\Enums\AddOnKind;
use App\Enums\AddOnPricing;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\AddOnFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AddOn extends Model
{
    /** @use HasFactory<AddOnFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'kind',
        'pricing',
        'amount_cents',
        'active',
        'max_quantity',
    ];

    protected $casts = [
        'kind' => AddOnKind::class,
        'pricing' => AddOnPricing::class,
        'amount_cents' => 'integer',
        'active' => 'boolean',
        'max_quantity' => 'integer',
    ];

    /**
     * @return HasMany<BookingAddOn, $this>
     */
    public function bookingLines(): HasMany
    {
        return $this->hasMany(BookingAddOn::class);
    }
}
