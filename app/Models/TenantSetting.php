<?php

namespace App\Models;

use App\Enums\FeePayer;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'tenant_id',
    'hold_minutes',
    'ledger_bucket_minutes',
    'fee_payer',
    'direct_fee_bps',
    'third_party_fee_bps',
    'direct_fee_fixed_cents',
    'third_party_fee_fixed_cents',
    'direct_fee_minimum_cents',
    'third_party_fee_minimum_cents',
    'currency',
])]
class TenantSetting extends Model
{
    /** @use HasFactory<TenantSettingFactory> */
    use BelongsToTenant, HasFactory, HasUlids;

    protected $casts = [
        'fee_payer' => FeePayer::class,
        'hold_minutes' => 'integer',
        'ledger_bucket_minutes' => 'integer',
        'direct_fee_bps' => 'integer',
        'third_party_fee_bps' => 'integer',
        'direct_fee_fixed_cents' => 'integer',
        'third_party_fee_fixed_cents' => 'integer',
        'direct_fee_minimum_cents' => 'integer',
        'third_party_fee_minimum_cents' => 'integer',
    ];
}
