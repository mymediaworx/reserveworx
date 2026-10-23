<?php

namespace App\Services\Payments;

use App\Domain\Payments\StripeGateway;
use App\Domain\Payments\StripeNotConfiguredException;

final class UnconfiguredStripeGateway implements StripeGateway
{
    public function createMerchantAccount(array $params): array
    {
        throw new StripeNotConfiguredException;
    }

    public function createOnboardingLink(array $params): array
    {
        throw new StripeNotConfiguredException;
    }

    public function createDirectCheckoutSession(string $accountId, array $params): array
    {
        throw new StripeNotConfiguredException;
    }

    public function parseEvent(string $payload, string $signature): array
    {
        throw new StripeNotConfiguredException;
    }

    public function createRefund(string $accountId, array $params, string $idempotencyKey): array
    {
        throw new StripeNotConfiguredException;
    }

    public function createApplicationFeeRefund(string $feeId, int $amount, string $idempotencyKey): array
    {
        throw new StripeNotConfiguredException;
    }
}
