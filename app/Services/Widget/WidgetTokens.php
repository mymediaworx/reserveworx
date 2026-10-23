<?php

namespace App\Services\Widget;

use App\Models\Tenant;
use App\Models\WidgetToken;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class WidgetTokens
{
    /**
     * @return array{token: WidgetToken, plain: string}
     */
    public function issue(Tenant $tenant, string $name, ?CarbonImmutable $expiresAt): array
    {
        $plain = Str::random(40);
        $token = WidgetToken::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => $expiresAt,
        ]);

        return ['token' => $token, 'plain' => $plain];
    }

    public function findActive(string $plain): ?WidgetToken
    {
        if ($plain === '') {
            return null;
        }

        $token = WidgetToken::query()->where('token_hash', hash('sha256', $plain))->first();

        if (! $token instanceof WidgetToken || ! $token->isActive()) {
            return null;
        }

        return $token;
    }
}
