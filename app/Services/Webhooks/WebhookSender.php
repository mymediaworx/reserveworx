<?php

namespace App\Services\Webhooks;

use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Models\WebhookEndpoint;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class WebhookSender
{
    public function __construct(private readonly PublicHttpsEndpoint $urls) {}

    public function send(WebhookDelivery $delivery): void
    {
        $endpoint = $delivery->endpoint;

        if (! $endpoint instanceof WebhookEndpoint || ! $endpoint->active || $endpoint->tenant_id !== $delivery->tenant_id) {
            $this->record($delivery, null, 0, 'The endpoint is not active.', false);
            $delivery->status = 'failed';
            $delivery->save();

            return;
        }

        $started = hrtime(true);
        $code = null;
        $responseBody = 'The endpoint could not be reached.';
        $ok = false;

        try {
            $resolved = $this->urls->resolve($endpoint->url);
            $body = $this->body($delivery);
            $timestamp = (string) now()->getTimestamp();
            $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $endpoint->secret);
            $response = Http::withOptions($this->options($resolved))
                ->withHeaders([
                    'User-Agent' => 'ReserveWorx-Webhooks/1',
                    'X-ReserveWorx-Event' => $delivery->event,
                    'X-ReserveWorx-Delivery' => $delivery->id,
                    'X-ReserveWorx-Timestamp' => $timestamp,
                    'X-ReserveWorx-Signature' => 'v1='.$signature,
                ])
                ->withBody($body, 'application/json')
                ->post($resolved->url);
            $code = $response->status();
            $responseBody = $response->body();
            $ok = $code >= 200 && $code < 300;
        } catch (UnsafeWebhookUrlException) {
            $responseBody = 'The endpoint address is not public.';
        } catch (ConnectionException) {
            $responseBody = 'The endpoint could not be reached.';
        }

        $duration = (int) max(0, round((hrtime(true) - $started) / 1_000_000));
        $this->record($delivery, $code, $duration, $responseBody, $ok);

        if ($ok) {
            $delivery->status = 'succeeded';
            $delivery->save();

            return;
        }

        throw new WebhookAttemptFailed('Webhook delivery failed.');
    }

    /**
     * @return array<string, mixed>
     */
    private function options(ResolvedWebhookUrl $resolved): array
    {
        $curl = [];

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curl[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if (defined('CURLOPT_REDIR_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
            $curl[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        if (is_string($resolved->address) && $resolved->address !== '') {
            $address = str_contains($resolved->address, ':') ? '['.$resolved->address.']' : $resolved->address;
            $curl[CURLOPT_RESOLVE] = [$resolved->host.':'.$resolved->port.':'.$address];
        }

        return [
            'allow_redirects' => false,
            'timeout' => 8,
            'connect_timeout' => 3,
            'proxy' => '',
            'curl' => $curl,
        ];
    }

    private function body(WebhookDelivery $delivery): string
    {
        $createdAt = $delivery->created_at;

        return json_encode([
            'id' => $delivery->id,
            'type' => $delivery->event,
            'created_at' => $createdAt !== null ? $createdAt->clone()->utc()->toIso8601String() : now()->utc()->toIso8601String(),
            'data' => $delivery->payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function record(WebhookDelivery $delivery, ?int $code, int $duration, string $body, bool $ok): void
    {
        $attempt = $delivery->logs()->count() + 1;
        $delivery->attempt_count = $attempt;
        $delivery->save();

        WebhookDeliveryAttempt::query()->create([
            'tenant_id' => $delivery->tenant_id,
            'webhook_delivery_id' => $delivery->id,
            'attempt' => $attempt,
            'status' => $ok ? 'succeeded' : 'failed',
            'response_code' => $code,
            'duration_ms' => $duration,
            'response_body' => $this->truncate($body),
        ]);
    }

    private function truncate(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        return mb_substr(mb_scrub($body, 'UTF-8'), 0, WebhookDeliveryAttempt::BODY_LIMIT);
    }
}
