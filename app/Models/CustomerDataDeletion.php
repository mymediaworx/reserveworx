<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerDataDeletionFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDataDeletion extends Model
{
    /** @use HasFactory<CustomerDataDeletionFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'actor_user_id',
        'export_id',
        'confirmed',
        'anonymized_at',
    ];

    protected $casts = [
        'confirmed' => 'boolean',
        'anonymized_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<CustomerDataExport, $this>
     */
    public function export(): BelongsTo
    {
        return $this->belongsTo(CustomerDataExport::class, 'export_id');
    }
}
