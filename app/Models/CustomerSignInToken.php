<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSignInToken extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'customer_account_id',
        'token_hash',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
