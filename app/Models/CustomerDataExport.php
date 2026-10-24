<?php

namespace App\Models;

use App\Enums\CustomerDataExportStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CustomerDataExportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDataExport extends Model
{
    /** @use HasFactory<CustomerDataExportFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    public const DISK = 'local';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'actor_user_id',
        'disk',
        'path',
        'bytes',
        'row_counts',
        'status',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'row_counts' => 'array',
        'status' => CustomerDataExportStatus::class,
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
