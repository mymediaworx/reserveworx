<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    /**
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'password',
        'password_confirmation',
        'token',
        'secret',
        'authorization',
        'client_secret',
        'remember_token',
        'ssh_key',
        'passphrase',
    ];

    /**
     * @param  array<string, mixed>  $properties
     */
    public function log(
        string $action,
        ?Model $subject = null,
        array $properties = [],
        ?string $tenantId = null,
        ?User $actor = null,
        ?Request $request = null,
        bool $useContextTenant = true,
    ): AuditLog {
        $resolvedTenantId = $tenantId;

        if ($resolvedTenantId === null && $useContextTenant) {
            $resolvedTenantId = app(TenantManager::class)->id();
        }

        $actor ??= $request?->user() instanceof User ? $request->user() : null;

        return AuditLog::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $resolvedTenantId,
            'actor_user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject === null ? null : $subject::class,
            'subject_id' => $subject?->getKey(),
            'properties' => $this->scrub($properties),
            'ip_address' => $request?->ip(),
            'user_agent' => $request === null ? null : mb_substr((string) $request->userAgent(), 0, 1000),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<mixed>  $properties
     * @return array<mixed>
     */
    private function scrub(array $properties): array
    {
        $clean = [];

        foreach ($properties as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->scrub($value) : $value;
        }

        return $clean;
    }
}
