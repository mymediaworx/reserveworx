<?php

namespace App\Domain\Payments;

/**
 * Checkout Session params for a direct charge on the connected account.
 * Payment method types are omitted so Stripe can choose eligible methods.
 */
final class CheckoutSessionSpec
{
    /**
     * @return array<string, mixed>
     */
    public static function directCharge(
        string $tenantId,
        string $bookingId,
        string $serviceName,
        string $currency,
        int $chargeCents,
        ?int $applicationFeeCents,
        string $successUrl,
        string $cancelUrl,
        string $integrationIdentifier,
    ): array {
        $intent = [
            'metadata' => [
                'booking_id' => $bookingId,
                'tenant_id' => $tenantId,
            ],
        ];

        if ($applicationFeeCents !== null && $applicationFeeCents > 0) {
            $intent['application_fee_amount'] = $applicationFeeCents;
        }

        return [
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'client_reference_id' => $bookingId,
            'metadata' => [
                'booking_id' => $bookingId,
                'tenant_id' => $tenantId,
            ],
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $chargeCents,
                    'product_data' => ['name' => $serviceName],
                ],
            ]],
            'payment_intent_data' => $intent,
            'integration_identifier' => $integrationIdentifier,
        ];
    }
}
