<?php

namespace App\Domain\Payments;

interface StripeGateway
{
    /**
     * @param  array<string, mixed>  $params
     * @return array{id: string, card_payments_status: string, livemode: bool}
     */
    public function createMerchantAccount(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array{url: string}
     */
    public function createOnboardingLink(array $params): array;

    /**
     * @param  array<string, mixed>  $params
     * @return array{id: string, url: string}
     */
    public function createDirectCheckoutSession(string $accountId, array $params): array;

    /**
     * @return array{id: string, type: string, account: ?string, object: array<string, mixed>}
     */
    public function parseEvent(string $payload, string $signature): array;

    /**
     * Refund a direct charge on the connected account. `refund_application_fee` is set only
     * for a full refund that returns the whole application fee.
     *
     * @param  array<string, mixed>  $params
     * @return array{id: string}
     */
    public function createRefund(string $accountId, array $params, string $idempotencyKey): array;

    /**
     * Refund part of an application fee from the platform account.
     *
     * @return array{id: string}
     */
    public function createApplicationFeeRefund(string $feeId, int $amount, string $idempotencyKey): array;
}
