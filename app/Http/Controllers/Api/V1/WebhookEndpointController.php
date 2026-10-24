<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreWebhookEndpointRequest;
use App\Http\Requests\Api\V1\UpdateWebhookEndpointRequest;
use App\Http\Resources\WebhookEndpointResource;
use App\Models\Tenant;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class WebhookEndpointController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): AnonymousResourceCollection
    {
        $this->authorize('viewAny', WebhookEndpoint::class);

        return WebhookEndpointResource::collection(
            WebhookEndpoint::query()->orderByDesc('created_at')->orderByDesc('id')->get(),
        );
    }

    public function store(StoreWebhookEndpointRequest $request): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $secret = 'whsec_'.bin2hex(random_bytes(32));

        try {
            $endpoint = WebhookEndpoint::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $this->name($request->validated('name')),
                'url' => $request->string('url')->toString(),
                'secret' => $secret,
                'events' => array_values($request->input('events', [])),
                'active' => $request->has('active') ? $request->boolean('active') : true,
            ]);
        } catch (QueryException $exception) {
            $this->rethrowDuplicate($exception);
        }

        $this->audit->log('webhook_endpoint.created', $endpoint, [
            'name' => $endpoint->name,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'active' => $endpoint->active,
        ], request: $request);

        $payload = (new WebhookEndpointResource($endpoint))->resolve($request);
        $payload['secret'] = $secret;

        return response()->json(['data' => $payload], 201);
    }

    public function show(WebhookEndpoint $webhook_endpoint): WebhookEndpointResource
    {
        $this->authorize('view', $webhook_endpoint);

        return new WebhookEndpointResource($webhook_endpoint);
    }

    public function update(UpdateWebhookEndpointRequest $request, WebhookEndpoint $webhook_endpoint): WebhookEndpointResource
    {
        $before = [
            'name' => $webhook_endpoint->name,
            'url' => $webhook_endpoint->url,
            'events' => $webhook_endpoint->events,
            'active' => $webhook_endpoint->active,
        ];

        if ($request->exists('name')) {
            $webhook_endpoint->name = $this->name($request->validated('name'));
        }

        if ($request->exists('url')) {
            $webhook_endpoint->url = $request->string('url')->toString();
        }

        if ($request->exists('events')) {
            $events = $request->validated('events');
            $webhook_endpoint->events = is_array($events) ? array_values($events) : [];
        }

        if ($request->exists('active')) {
            $webhook_endpoint->active = $request->boolean('active');
        }

        try {
            $webhook_endpoint->save();
        } catch (QueryException $exception) {
            $this->rethrowDuplicate($exception);
        }

        $this->audit->log('webhook_endpoint.updated', $webhook_endpoint, [
            'before' => $before,
            'after' => [
                'name' => $webhook_endpoint->name,
                'url' => $webhook_endpoint->url,
                'events' => $webhook_endpoint->events,
                'active' => $webhook_endpoint->active,
            ],
        ], request: $request);

        return new WebhookEndpointResource($webhook_endpoint);
    }

    public function destroy(Request $request, WebhookEndpoint $webhook_endpoint): JsonResponse
    {
        $this->authorize('delete', $webhook_endpoint);
        $this->audit->log('webhook_endpoint.deleted', $webhook_endpoint, [
            'url' => $webhook_endpoint->url,
        ], request: $request);
        $webhook_endpoint->delete();

        return response()->json(null, 204);
    }

    private function name(mixed $name): ?string
    {
        return is_string($name) && $name !== '' ? $name : null;
    }

    private function rethrowDuplicate(QueryException $exception): never
    {
        if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
            throw $exception;
        }

        throw ValidationException::withMessages([
            'url' => 'That endpoint is already registered.',
        ]);
    }
}
