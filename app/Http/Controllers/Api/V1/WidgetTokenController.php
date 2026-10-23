<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Tenant;
use App\Models\WidgetToken;
use App\Services\Widget\WidgetTokens;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WidgetTokenController extends Controller
{
    public function __construct(private readonly WidgetTokens $tokens) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Booking::class);

        $rows = WidgetToken::query()->orderBy('name')->get()->map(fn (WidgetToken $token): array => [
            'id' => $token->id,
            'name' => $token->name,
            'expires_at' => $token->expires_at?->toIso8601String(),
            'revoked_at' => $token->revoked_at?->toIso8601String(),
            'active' => $token->isActive(),
        ]);

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Booking::class);
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'expires_at' => ['nullable', 'date'],
        ]);
        $expires = isset($validated['expires_at']) && is_string($validated['expires_at'])
            ? CarbonImmutable::parse($validated['expires_at'])->utc()
            : null;
        $issued = $this->tokens->issue($tenant, $validated['name'], $expires);

        return response()->json(['data' => [
            'id' => $issued['token']->id,
            'name' => $issued['token']->name,
            'token' => $issued['plain'],
            'embed_path' => '/book/'.$issued['plain'],
            'expires_at' => $issued['token']->expires_at?->toIso8601String(),
        ]], 201);
    }

    public function revoke(Request $request, WidgetToken $widgetToken): JsonResponse
    {
        $this->authorize('create', Booking::class);
        $widgetToken->revoked_at = now();
        $widgetToken->save();

        return response()->json(['data' => ['id' => $widgetToken->id, 'active' => false]]);
    }
}
