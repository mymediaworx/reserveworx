<?php

namespace App\Services\Payments;

use App\Domain\Payments\StripeGateway;
use JsonException;

/**
 * In-memory Stripe stand-in for tests. It records the requests the app sends
 * and accepts a webhook only when the signature is "test-signature".
 */
final class FakeStripeGateway implements StripeGateway
{
    /** @var list<array<string, mixed>> */
    public array $accounts = [];

    /** @var list<array{account: string, params: array<string, mixed>}> */
    public array $sessions = [];

    public string $accountId = 'acct_test_123';

    public string $sessionId = 'cs_test_123';

    public string $sessionUrl = 'https://checkout.stripe.test/pay/cs_test_123';

    public function createMerchantAccount(array $params): array
    {
        $this->accounts[] = $params;

        return [
            'id' => $this->accountId,
            'card_payments_status' => 'pending',
            'livemode' => false,
        ];
    }

    public function createOnboardingLink(array $params): array
    {
        return ['url' => 'https://connect.stripe.test/setup/'.$this->accountId];
    }

    public function createDirectCheckoutSession(string $accountId, array $params): array
    {
        $this->sessions[] = ['account' => $accountId, 'params' => $params];

        return ['id' => $this->sessionId, 'url' => $this->sessionUrl];
    }

    public function parseEvent(string $payload, string $signature): array
    {
        if (! hash_equals('test-signature', $signature)) {
            throw new InvalidStripeSignatureException('Invalid Stripe signature.');
        }

        try {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidStripeSignatureException('Invalid Stripe payload.', previous: $exception);
        }

        $object = $decoded['data']['object'] ?? [];

        return [
            'id' => is_string($decoded['id'] ?? null) ? $decoded['id'] : 'evt_test',
            'type' => is_string($decoded['type'] ?? null) ? $decoded['type'] : 'unknown',
            'account' => is_string($decoded['account'] ?? null) ? $decoded['account'] : null,
            'object' => is_array($object) ? $object : [],
        ];
    }
}
