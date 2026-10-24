<?php

namespace App\Services\Widget;

use App\Models\Tenant;
use App\Models\WidgetToken;
use App\Tenancy\TenantScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use RuntimeException;

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

    /**
     * Plain token for the workspace's own booking page. The same value is derived
     * whenever the page is opened, and it is stored only as a hash.
     */
    public function hostedPlain(Tenant $tenant): string
    {
        $plain = 'hosted_'.hash_hmac('sha256', (string) $tenant->id, (string) config('app.key'));
        $hash = hash('sha256', $plain);

        try {
            $token = WidgetToken::query()->withoutGlobalScope(TenantScope::class)->firstOrCreate(
                ['token_hash' => $hash],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Hosted booking page',
                    'expires_at' => null,
                ],
            );
        } catch (UniqueConstraintViolationException) {
            $found = WidgetToken::query()->withoutGlobalScope(TenantScope::class)->where('token_hash', $hash)->first();
            $token = $found instanceof WidgetToken ? $found : null;
        }

        if (! $token instanceof WidgetToken || $token->tenant_id !== $tenant->id) {
            throw new RuntimeException('Hosted booking token does not belong to this workspace.');
        }

        if (! $token->isActive()) {
            WidgetToken::query()
                ->withoutGlobalScope(TenantScope::class)
                ->whereKey($token->getKey())
                ->where('tenant_id', $tenant->id)
                ->update([
                    'revoked_at' => null,
                    'expires_at' => null,
                ]);
        }

        return $plain;
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
