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

    /** @var list<array{account: string, params: array<string, mixed>, idempotency_key: string}> */
    public array $refunds = [];

    /** @var list<array{fee: string, amount: int, idempotency_key: string}> */
    public array $feeRefunds = [];

    /** @var list<array{gte: int, lt: int}> */
    public array $feeLists = [];

    /** @var list<array{id: string, amount: int, amount_refunded: int, account: ?string, created: int}> */
    public array $applicationFees = [];

    public bool $truncateFeeList = false;

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

    public function createRefund(string $accountId, array $params, string $idempotencyKey): array
    {
        $this->refunds[] = [
            'account' => $accountId,
            'params' => $params,
            'idempotency_key' => $idempotencyKey,
        ];

        return ['id' => 're_test_'.count($this->refunds)];
    }

    public function createApplicationFeeRefund(string $feeId, int $amount, string $idempotencyKey): array
    {
        $this->feeRefunds[] = [
            'fee' => $feeId,
            'amount' => $amount,
            'idempotency_key' => $idempotencyKey,
        ];

        return ['id' => 'fr_test_'.count($this->feeRefunds)];
    }

    public function listApplicationFees(int $createdGte, int $createdLt): array
    {
        $this->feeLists[] = ['gte' => $createdGte, 'lt' => $createdLt];

        if ($this->truncateFeeList) {
            throw new FeeListTruncatedException;
        }

        $rows = [];

        foreach ($this->applicationFees as $fee) {
            if ($fee['created'] >= $createdGte && $fee['created'] < $createdLt) {
                $rows[] = $fee;
            }
        }

        return $rows;
    }
}
