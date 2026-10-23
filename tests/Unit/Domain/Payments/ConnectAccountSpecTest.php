<?php

namespace Tests\Unit\Domain\Payments;

use App\Domain\Payments\ConnectAccountSpec;
use PHPUnit\Framework\TestCase;

class ConnectAccountSpecTest extends TestCase
{
    public function test_a_merchant_account_uses_accounts_v2_direct_charges(): void
    {
        $params = ConnectAccountSpec::merchant('01TENANT', 'Harbor Co', 'owner@harbor.test');

        $this->assertSame('full', $params['dashboard']);
        $this->assertSame('stripe', $params['defaults']['responsibilities']['fees_collector']);
        $this->assertSame('stripe', $params['defaults']['responsibilities']['losses_collector']);
        $this->assertTrue($params['configuration']['merchant']['capabilities']['card_payments']['requested']);
        $this->assertSame(['configuration.merchant'], $params['include']);
        $this->assertArrayNotHasKey('type', $params);
        $this->assertArrayNotHasKey('customer', $params['configuration']);
        $this->assertSame('2026-09-30.preview', ConnectAccountSpec::API_VERSION);
    }

    public function test_card_payments_status_is_read_only_when_the_payload_carries_it(): void
    {
        $active = [
            'id' => 'acct_123',
            'configuration' => [
                'merchant' => [
                    'capabilities' => [
                        'card_payments' => ['status' => 'active'],
                    ],
                ],
            ],
        ];

        $this->assertSame('active', ConnectAccountSpec::cardPaymentsStatus($active));
        $this->assertSame('active', ConnectAccountSpec::readCardPaymentsStatus($active));
        $this->assertNull(ConnectAccountSpec::readCardPaymentsStatus(['id' => 'acct_123', 'charges_enabled' => true]));
        $this->assertSame('pending', ConnectAccountSpec::cardPaymentsStatus(['id' => 'acct_123']));
    }
}
