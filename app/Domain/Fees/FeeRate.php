<?php

namespace App\Domain\Fees;

use App\Models\TenantSetting;
use InvalidArgumentException;

/**
 * One channel's fee rate for a tenant (spec Section 7.4).
 *
 * A percentage in basis points, an optional fixed amount (0 = disabled),
 * and an optional minimum (null = disabled).
 */
final readonly class FeeRate
{
    public const MAX_BASIS_POINTS = 10_000;

    public function __construct(
        public int $basisPoints,
        public int $fixedCents = 0,
        public ?int $minimumCents = null,
    ) {
        if ($basisPoints < 0 || $basisPoints > self::MAX_BASIS_POINTS) {
            throw new InvalidArgumentException('Fee basis points must be between 0 and 10000.');
        }

        if ($fixedCents < 0) {
            throw new InvalidArgumentException('Fixed fee must not be negative.');
        }

        if ($minimumCents !== null && $minimumCents < 0) {
            throw new InvalidArgumentException('Minimum fee must not be negative.');
        }
    }

    /**
     * Reads the stored rate without touching the database.
     */
    public static function fromTenantSetting(TenantSetting $setting, FeeChannel $channel): self
    {
        return match ($channel) {
            FeeChannel::Direct => new self(
                (int) $setting->direct_fee_bps,
                (int) $setting->direct_fee_fixed_cents,
                $setting->direct_fee_minimum_cents === null ? null : (int) $setting->direct_fee_minimum_cents,
            ),
            FeeChannel::ThirdParty => new self(
                (int) $setting->third_party_fee_bps,
                (int) $setting->third_party_fee_fixed_cents,
                $setting->third_party_fee_minimum_cents === null ? null : (int) $setting->third_party_fee_minimum_cents,
            ),
        };
    }

    /**
     * Spec defaults: 3% direct, 1% third-party, fixed and minimum disabled.
     */
    public static function defaultFor(FeeChannel $channel): self
    {
        return match ($channel) {
            FeeChannel::Direct => new self(300),
            FeeChannel::ThirdParty => new self(100),
        };
    }

    /**
     * @return array{basis_points: int, fixed_cents: int, minimum_cents: int|null}
     */
    public function toArray(): array
    {
        return [
            'basis_points' => $this->basisPoints,
            'fixed_cents' => $this->fixedCents,
            'minimum_cents' => $this->minimumCents,
        ];
    }
}
