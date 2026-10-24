<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Support\Hostname;
use App\Tenancy\TenantScope;
use Illuminate\Console\Command;

class AttachVerifiedDomain extends Command
{
    protected $signature = 'reserveworx:attach-verified-domain {slug} {hostname}';

    protected $description = 'Attach a verified hostname to an existing workspace';

    public function handle(AuditLogger $audit): int
    {
        $slug = (string) $this->argument('slug');
        $host = Hostname::normalize((string) $this->argument('hostname'));

        if (! Hostname::isValid($host)) {
            $this->error('hostname is not valid.');

            return self::FAILURE;
        }

        $platform = config('reserveworx.platform_domains', []);

        if (is_array($platform) && in_array($host, $platform, true)) {
            $this->error('hostname is reserved for the platform.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant instanceof Tenant) {
            $this->error('tenant was not found.');

            return self::FAILURE;
        }

        $existing = Domain::withoutGlobalScope(TenantScope::class)->where('hostname', $host)->first();

        if ($existing instanceof Domain && $existing->tenant_id !== $tenant->id) {
            $this->error('hostname belongs to another workspace.');

            return self::FAILURE;
        }

        if ($existing instanceof Domain) {
            if ($existing->verified_at === null) {
                $existing->verified_at = now();
                $existing->save();
                $this->auditVerified($audit, $existing, $tenant->id, $host);
            }

            $this->info('domain_attached=existing hostname='.$host.' tenant='.$slug);

            return self::SUCCESS;
        }

        $domain = Domain::withoutGlobalScope(TenantScope::class)->create([
            'tenant_id' => $tenant->id,
            'hostname' => $host,
            'type' => 'subdomain',
            'is_primary' => false,
            'verified_at' => now(),
        ]);

        $audit->log(
            action: 'domain.created',
            subject: $domain,
            properties: ['hostname' => $host, 'type' => 'subdomain'],
            tenantId: $tenant->id,
            useContextTenant: false,
        );
        $this->auditVerified($audit, $domain, $tenant->id, $host);
        $this->info('domain_attached=created hostname='.$host.' tenant='.$slug);

        return self::SUCCESS;
    }

    private function auditVerified(AuditLogger $audit, Domain $domain, string $tenantId, string $host): void
    {
        $audit->log(
            action: 'domain.verified',
            subject: $domain,
            properties: ['hostname' => $host, 'method' => 'platform_attestation'],
            tenantId: $tenantId,
            useContextTenant: false,
        );
    }
}
