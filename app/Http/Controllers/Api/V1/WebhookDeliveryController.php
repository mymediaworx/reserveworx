<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\WebhookEvent;
use App\Http\Controllers\Controller;
use App\Http\Resources\WebhookDeliveryResource;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WebhookDeliveryController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WebhookDelivery::class);

        $validated = $request->validate([
            'event' => ['sometimes', 'nullable', 'string', Rule::in(WebhookEvent::values())],
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['pending', 'succeeded', 'failed'])],
            'webhook_endpoint_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);

        $event = $this->filter($validated['event'] ?? null);
        $status = $this->filter($validated['status'] ?? null);
        $endpointId = $this->filter($validated['webhook_endpoint_id'] ?? null);

        if ($endpointId !== null && ! WebhookEndpoint::query()->whereKey($endpointId)->exists()) {
            abort(404);
        }

        $deliveries = WebhookDelivery::query()
            ->with('logs')
            ->when($event !== null, fn ($query) => $query->where('event', $event))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->when($endpointId !== null, fn ($query) => $query->where('webhook_endpoint_id', $endpointId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return WebhookDeliveryResource::collection($deliveries);
    }

    public function show(WebhookDelivery $webhook_delivery): WebhookDeliveryResource
    {
        $this->authorize('view', $webhook_delivery);

        return new WebhookDeliveryResource($webhook_delivery->load('logs'));
    }

    public function redeliver(Request $request, WebhookDelivery $webhook_delivery): JsonResponse
    {
        $this->authorize('redeliver', $webhook_delivery);

        if ($webhook_delivery->status === 'pending') {
            abort(409, 'This delivery is already queued.');
        }

        $endpoint = $webhook_delivery->endpoint;

        if (! $endpoint instanceof WebhookEndpoint || ! $endpoint->active) {
            abort(409, 'That endpoint is not active.');
        }

        $webhook_delivery->status = 'pending';
        $webhook_delivery->save();
        DeliverWebhook::dispatch($webhook_delivery->tenant_id, $webhook_delivery->id);
        $this->audit->log('webhook_delivery.redelivered', $webhook_delivery, [
            'event' => $webhook_delivery->event,
            'webhook_endpoint_id' => $webhook_delivery->webhook_endpoint_id,
        ], request: $request);

        return (new WebhookDeliveryResource($webhook_delivery->load('logs')))
            ->response()
            ->setStatusCode(202);
    }

    private function filter(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
