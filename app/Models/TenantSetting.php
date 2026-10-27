<?php

namespace App\Models;

use App\Enums\FeePayer;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantSetting extends Model
{
    protected $fillable = [
        'tenant_id',
        'hold_minutes',
        'guest_cancel_before_minutes',
        'late_fee_per_minute_cents',
        'late_grace_minutes',
        'ledger_bucket_minutes',
        'fee_payer',
        'direct_fee_bps',
        'third_party_fee_bps',
        'direct_fee_fixed_cents',
        'third_party_fee_fixed_cents',
        'direct_fee_minimum_cents',
        'third_party_fee_minimum_cents',
        'currency',
        'pay_at_pickup',
        'timezone',
        'email_payment_receipt',
        'reminder_offsets_minutes',
    ];

    public const DEFAULT_TIMEZONE = 'America/New_York';

    /**
     * Capacity-ledger block length, in minutes. Spec Section 4.3.
     *
     * @var list<int>
     */
    public const LEDGER_BUCKET_MINUTES = [5, 10, 15, 30, 60];

    /**
     * Elapsed minutes before a confirmed booking starts. Null in the database uses this list.
     *
     * @var list<int>
     */
    public const DEFAULT_REMINDER_OFFSETS_MINUTES = [1440, 120];

    protected $attributes = [
        'timezone' => self::DEFAULT_TIMEZONE,
        'email_payment_receipt' => false,
    ];

    /** @use HasFactory<TenantSettingFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $casts = [
        'fee_payer' => FeePayer::class,
        'hold_minutes' => 'integer',
        'guest_cancel_before_minutes' => 'integer',
        'late_fee_per_minute_cents' => 'integer',
        'late_grace_minutes' => 'integer',
        'ledger_bucket_minutes' => 'integer',
        'direct_fee_bps' => 'integer',
        'third_party_fee_bps' => 'integer',
        'direct_fee_fixed_cents' => 'integer',
        'third_party_fee_fixed_cents' => 'integer',
        'direct_fee_minimum_cents' => 'integer',
        'third_party_fee_minimum_cents' => 'integer',
        'pay_at_pickup' => 'boolean',
        'email_payment_receipt' => 'boolean',
        'reminder_offsets_minutes' => 'array',
    ];

    /**
     * @return list<int>
     */
    public function reminderOffsets(): array
    {
        $stored = $this->reminder_offsets_minutes;

        if (! is_array($stored)) {
            return self::DEFAULT_REMINDER_OFFSETS_MINUTES;
        }

        $offsets = [];

        foreach ($stored as $offset) {
            if (is_int($offset)) {
                $offsets[] = $offset;
            } elseif (is_string($offset) && ctype_digit($offset)) {
                $offsets[] = (int) $offset;
            }
        }

        return array_values(array_unique($offsets));
    }
}
