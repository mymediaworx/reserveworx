<?php

namespace App\Models;

use App\Enums\DisclosureKind;
use App\Enums\FeePayer;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One published version of customer-facing disclosure text (spec Section 7.6 item 8).
 *
 * Versions are immutable once written. A change in wording is a new version,
 * so a booking's recorded version always shows exactly what the customer saw.
 *
 * @property string $id
 * @property string $tenant_id
 * @property DisclosureKind $kind
 * @property int $version
 * @property FeePayer $fee_payer
 * @property string $rules_version
 * @property string $body
 * @property string $content_hash
 * @property Carbon $published_at
 */
class DisclosureVersion extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'kind',
        'version',
        'fee_payer',
        'rules_version',
        'body',
        'content_hash',
        'published_at',
    ];

    protected $casts = [
        'kind' => DisclosureKind::class,
        'fee_payer' => FeePayer::class,
        'version' => 'integer',
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Disclosure versions are immutable. Publish a new version instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('Disclosure versions are kept as a record of what customers saw.');
        });
    }

    /**
     * @param  Builder<DisclosureVersion>  $query
     * @return Builder<DisclosureVersion>
     */
    public function scopeOfKind(Builder $query, DisclosureKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    /**
     * The disclosure lines, one sentence per line.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        return array_values(array_filter(
            array_map('trim', explode("\n", $this->body)),
            static fn (string $line): bool => $line !== '',
        ));
    }
}
