<?php

namespace Tests\Unit\Domain\Payments;

use App\Domain\Payments\CheckoutSessionSpec;
use PHPUnit\Framework\TestCase;

class CheckoutSessionSpecTest extends TestCase
{
    public function test_a_positive_fee_is_sent_as_application_fee_amount(): void
    {
        $params = $this->params(300);

        $this->assertSame(300, $params['payment_intent_data']['application_fee_amount']);
        $this->assertArrayNotHasKey('payment_method_types', $params);
        $this->assertSame('payment', $params['mode']);
        $this->assertSame(10_300, $params['line_items'][0]['price_data']['unit_amount']);
    }

    public function test_a_zero_fee_omits_application_fee_amount(): void
    {
        $params = $this->params(null);

        $this->assertArrayNotHasKey('application_fee_amount', $params['payment_intent_data']);
        $this->assertArrayNotHasKey('payment_method_types', $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function params(?int $fee): array
    {
        return CheckoutSessionSpec::directCharge(
            '01TENANT',
            '01BOOKING',
            'Hourly boat',
            'USD',
            10_300,
            $fee,
            'https://dock.test/paid',
            'https://dock.test/cancel',
            'reserveworx_abcdefgh',
        );
    }
}
