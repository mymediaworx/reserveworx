<?php

namespace Tests\Unit\Domain\Fees;

use App\Domain\Fees\FeeBasis;
use App\Domain\Fees\FeeChannel;
use App\Domain\Fees\FeeRate;
use App\Models\TenantSetting;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FeeRateAndBasisTest extends TestCase
{
    public function test_spec_default_rates(): void
    {
        $direct = FeeRate::defaultFor(FeeChannel::Direct);
        $third = FeeRate::defaultFor(FeeChannel::ThirdParty);

        $this->assertSame(['basis_points' => 300, 'fixed_cents' => 0, 'minimum_cents' => null], $direct->toArray());
        $this->assertSame(['basis_points' => 100, 'fixed_cents' => 0, 'minimum_cents' => null], $third->toArray());
    }

    public function test_reads_each_channel_from_tenant_settings_without_the_database(): void
    {
        $setting = new TenantSetting;
        $setting->forceFill([
            'direct_fee_bps' => 250,
            'direct_fee_fixed_cents' => 30,
            'direct_fee_minimum_cents' => 75,
            'third_party_fee_bps' => 80,
            'third_party_fee_fixed_cents' => 0,
            'third_party_fee_minimum_cents' => null,
        ]);

        $this->assertSame(
            ['basis_points' => 250, 'fixed_cents' => 30, 'minimum_cents' => 75],
            FeeRate::fromTenantSetting($setting, FeeChannel::Direct)->toArray(),
        );
        $this->assertSame(
            ['basis_points' => 80, 'fixed_cents' => 0, 'minimum_cents' => null],
            FeeRate::fromTenantSetting($setting, FeeChannel::ThirdParty)->toArray(),
        );
    }

    /**
     * @return array<string, array{int, int, int|null}>
     */
    public static function invalidRates(): array
    {
        return [
            'negative bps' => [-1, 0, null],
            'bps over 100%' => [10_001, 0, null],
            'negative fixed' => [300, -1, null],
            'negative minimum' => [300, 0, -1],
        ];
    }

    #[DataProvider('invalidRates')]
    public function test_rejects_invalid_rates(int $bps, int $fixed, ?int $minimum): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeeRate($bps, $fixed, $minimum);
    }

    public function test_basis_is_services_plus_add_ons_plus_mandatory_fees_minus_discounts(): void
    {
        $basis = new FeeBasis(
            servicesCents: 10_000,
            addOnsCents: 2_500,
            mandatoryOperatorFeesCents: 500,
            discountsCents: 1_000,
        );

        $this->assertSame(12_000, $basis->cents());
    }

    public function test_full_discount_gives_zero_basis(): void
    {
        $this->assertSame(0, (new FeeBasis(5_000, 0, 0, 5_000))->cents());
    }

    public function test_discount_larger_than_subtotal_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeeBasis(5_000, 0, 0, 5_001);
    }

    public function test_negative_components_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeeBasis(5_000, 0, 0, -100);
    }
}
