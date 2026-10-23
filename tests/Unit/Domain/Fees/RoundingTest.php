<?php

namespace Tests\Unit\Domain\Fees;

use App\Domain\Fees\Rounding;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RoundingTest extends TestCase
{
    /**
     * @return array<string, array{int, int, int}>
     */
    public static function percentCases(): array
    {
        return [
            // amount cents, basis points, expected cents
            'exact' => [10_000, 300, 300],
            'below half rounds down' => [1_016, 300, 30],        // 30.48
            'exactly half rounds up' => [1_050, 300, 32],        // 31.50
            'just above half rounds up' => [1_017, 300, 31],     // 30.51
            'one cent at 3%' => [1, 300, 0],                     // 0.03
            '50 cents at 1% is exactly half' => [50, 100, 1],     // 0.50
            '49 cents at 1%' => [49, 100, 0],                    // 0.49
            'large amount' => [123_456_789, 300, 3_703_704],     // 3,703,703.67
            'zero amount' => [0, 300, 0],
            'zero rate' => [10_000, 0, 0],
            'full rate' => [12_345, 10_000, 12_345],
        ];
    }

    #[DataProvider('percentCases')]
    public function test_basis_points_round_half_up(int $amount, int $bps, int $expected): void
    {
        $this->assertSame($expected, Rounding::basisPointsOf($amount, $bps));
    }

    public function test_half_up_matches_reference_rounding_across_a_range(): void
    {
        foreach ([2, 3, 7, 100, 10_000] as $denominator) {
            for ($numerator = 0; $numerator <= 30_000; $numerator += 7) {
                $reference = (int) round($numerator / $denominator, 0, PHP_ROUND_HALF_UP);
                $this->assertSame($reference, Rounding::halfUp($numerator, $denominator), "{$numerator}/{$denominator}");
            }
        }
    }

    public function test_rejects_negative_numerator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rounding::halfUp(-1, 100);
    }

    public function test_rejects_non_positive_denominator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Rounding::halfUp(1, 0);
    }
}
