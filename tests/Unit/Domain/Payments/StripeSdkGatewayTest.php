<?php

namespace Tests\Unit\Domain\Payments;

use App\Services\Payments\InvalidStripeSignatureException;
use App\Services\Payments\StripeSdkGateway;
use PHPUnit\Framework\TestCase;
use Stripe\WebhookSignature;

class StripeSdkGatewayTest extends TestCase
{
    public function test_a_signed_snapshot_event_is_accepted_and_a_bad_signature_is_refused(): void
    {
        $secret = 'whsec_test_secret';
        $gateway = new StripeSdkGateway('sk_test_placeholder', $secret);
        $payload = json_encode([
            'id' => 'evt_test_signature',
            'object' => 'event',
            'api_version' => '2026-09-30.preview',
            'created' => time(),
            'type' => 'checkout.session.completed',
            'livemode' => false,
            'pending_webhooks' => 1,
            'request' => ['id' => null, 'idempotency_key' => null],
            'data' => [
                'object' => [
                    'id' => 'cs_test_signature',
                    'object' => 'checkout.session',
                    'payment_status' => 'paid',
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $header = WebhookSignature::generateSignatureHeader($payload, $secret);

        $event = $gateway->parseEvent($payload, $header);

        $this->assertSame('evt_test_signature', $event['id']);
        $this->assertSame('checkout.session.completed', $event['type']);
        $this->assertSame('cs_test_signature', $event['object']['id']);
        $this->assertNull($event['account']);

        $this->expectException(InvalidStripeSignatureException::class);
        $gateway->parseEvent($payload, 't=1,v1=deadbeef');
    }
}
