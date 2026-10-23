<?php

namespace App\Services\Payments;

use App\Domain\Payments\ConnectAccountSpec;
use App\Domain\Payments\StripeGateway;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

final class StripeSdkGateway implements StripeGateway
{
    public function __construct(
        private readonly string $secret,
        private readonly string $webhookSecret,
        private readonly string $apiVersion = ConnectAccountSpec::API_VERSION,
    ) {}

    public function createMerchantAccount(array $params): array
    {
        $account = $this->client()->v2->core->accounts->create($params);
        $payload = $account->toArray();

        return [
            'id' => $account->id,
            'card_payments_status' => ConnectAccountSpec::cardPaymentsStatus($payload),
            'livemode' => (bool) $account->livemode,
        ];
    }

    public function createOnboardingLink(array $params): array
    {
        $link = $this->client()->v2->core->accountLinks->create($params);

        return ['url' => (string) $link->url];
    }

    public function createDirectCheckoutSession(string $accountId, array $params): array
    {
        $session = $this->client()->checkout->sessions->create($params, ['stripe_account' => $accountId]);

        return [
            'id' => $session->id,
            'url' => (string) $session->url,
        ];
    }

    public function parseEvent(string $payload, string $signature): array
    {
        try {
            $event = Webhook::constructEvent($payload, $signature, $this->webhookSecret);
        } catch (SignatureVerificationException $exception) {
            throw new InvalidStripeSignatureException($exception->getMessage(), previous: $exception);
        }

        $object = $event->data->object;
        $encoded = json_encode($object, JSON_THROW_ON_ERROR);
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        $values = $event->toArray();
        $account = $values['account'] ?? null;

        return [
            'id' => $event->id,
            'type' => $event->type,
            'account' => is_string($account) ? $account : null,
            'object' => $decoded,
        ];
    }

    public function createRefund(string $accountId, array $params, string $idempotencyKey): array
    {
        $refund = $this->client()->refunds->create($params, [
            'stripe_account' => $accountId,
            'idempotency_key' => $idempotencyKey,
        ]);

        return ['id' => $refund->id];
    }

    public function createApplicationFeeRefund(string $feeId, int $amount, string $idempotencyKey): array
    {
        $refund = $this->client()->applicationFees->createRefund($feeId, [
            'amount' => $amount,
        ], [
            'idempotency_key' => $idempotencyKey,
        ]);

        return ['id' => $refund->id];
    }

    private function client(): StripeClient
    {
        return new StripeClient([
            'api_key' => $this->secret,
            'stripe_version' => $this->apiVersion,
        ]);
    }
}
