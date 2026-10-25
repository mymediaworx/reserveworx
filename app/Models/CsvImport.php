<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\ImportType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\CsvImportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property ImportType $type
 * @property ImportStatus $status
 * @property string $tenant_id
 * @property string|null $user_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $delimiter
 * @property list<string> $headers
 * @property array<string, int|null>|null $mapping
 * @property int $row_count
 * @property int $processed_rows
 * @property int $created_count
 * @property int $updated_count
 * @property int $skipped_count
 * @property int $next_row
 * @property int $data_offset
 * @property int $byte_offset
 * @property list<array{row: int, reason: string}>|null $skipped
 * @property string|null $failure
 * @property Carbon|null $completed_at
 * @property Carbon|null $failed_at
 */
class CsvImport extends Model
{
    /** @use HasFactory<CsvImportFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'type',
        'status',
        'disk',
        'path',
        'original_name',
        'delimiter',
        'headers',
        'mapping',
        'row_count',
        'processed_rows',
        'created_count',
        'updated_count',
        'skipped_count',
        'next_row',
        'data_offset',
        'byte_offset',
        'skipped',
        'failure',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'type' => ImportType::class,
        'status' => ImportStatus::class,
        'headers' => 'array',
        'mapping' => 'array',
        'row_count' => 'integer',
        'processed_rows' => 'integer',
        'created_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'next_row' => 'integer',
        'data_offset' => 'integer',
        'byte_offset' => 'integer',
        'skipped' => 'array',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
