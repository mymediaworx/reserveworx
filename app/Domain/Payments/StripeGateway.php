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
}
