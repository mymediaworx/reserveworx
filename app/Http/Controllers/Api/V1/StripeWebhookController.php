<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\StripeNotConfiguredException;
use App\Http\Controllers\Controller;
use App\Services\Payments\InvalidStripeSignatureException;
use App\Services\Payments\RefundException;
use App\Services\Payments\WebhookProcessor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __construct(private readonly WebhookProcessor $processor) {}

    public function store(Request $request): JsonResponse
    {
        $signature = $request->header('Stripe-Signature');

        if (! is_string($signature) || $signature === '') {
            return response()->json(['message' => 'Missing Stripe signature.'], 400);
        }

        try {
            $this->processor->handle($request->getContent(), $signature);
        } catch (InvalidStripeSignatureException|StripeNotConfiguredException) {
            return response()->json(['message' => 'Invalid Stripe signature.'], 400);
        } catch (RefundException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }

        return response()->json(['received' => true]);
    }
}
