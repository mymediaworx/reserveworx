<?php

namespace Database\Seeders;

use App\Enums\FeePayer;
use App\Enums\MembershipStatus;
use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Membership;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Database\Seeder;

class DemoTenantSeeder extends Seeder
{
    /**
     * Two demo workspaces with different fee-payer modes.
     * New passwords are written only to DEMO_LOGIN_FILE. They are not echoed.
     */
    public function run(): void
    {
        $ownerRole = Role::query()->where('slug', 'owner')->firstOrFail();
        $lines = [
            '# ReserveWorx demo logins. This file is not in git. Do not copy it into chat.',
            '',
        ];

        foreach ($this->definitions() as $definition) {
            $tenant = Tenant::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'name' => $definition['name'],
                    'status' => TenantStatus::Active,
                ],
            );

            TenantSetting::withoutGlobalScope(TenantScope::class)->updateOrCreate(
                ['tenant_id' => $tenant->id],
                ['fee_payer' => $definition['fee_payer']],
            );

            Domain::withoutGlobalScope(TenantScope::class)->updateOrCreate(
                ['hostname' => $definition['hostname']],
                [
                    'tenant_id' => $tenant->id,
                    'type' => 'subdomain',
                    'is_primary' => true,
                    'verified_at' => now(),
                ],
            );

            $user = User::query()->where('email', $definition['email'])->first();
            $password = null;

            if ($user === null) {
                $password = bin2hex(random_bytes(24));
                $user = User::query()->create([
                    'name' => $definition['owner'],
                    'email' => $definition['email'],
                    'password' => $password,
                ]);
            }

            Membership::withoutGlobalScope(TenantScope::class)->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                ],
                [
                    'role_id' => $ownerRole->id,
                    'status' => MembershipStatus::Active,
                ],
            );

            $lines[] = 'tenant='.$definition['slug'];
            $lines[] = 'fee_payer='.$definition['fee_payer']->value;
            $lines[] = 'hostname='.$definition['hostname'];
            $lines[] = 'email='.$definition['email'];
            $lines[] = $password === null ? 'password=unchanged' : 'password='.$password;
            $lines[] = '';

            $this->command?->info(sprintf(
                'demo_tenant=%s fee_payer=%s email=%s hostname=%s',
                $definition['slug'],
                $definition['fee_payer']->value,
                $definition['email'],
                $definition['hostname'],
            ));
        }

        $path = (string) env('DEMO_LOGIN_FILE', storage_path('app/demo-logins.txt'));
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($path, implode("\n", $lines));
        chmod($path, 0600);
        $this->command?->info('demo_login_file=written');
    }

    /**
     * @return list<array{name: string, slug: string, owner: string, email: string, hostname: string, fee_payer: FeePayer}>
     */
    private function definitions(): array
    {
        return [
            [
                'name' => 'Harbor Demo',
                'slug' => 'harbor-demo',
                'owner' => 'Harbor Demo Owner',
                'email' => 'harbor-owner@demo.reserveworx.test',
                'hostname' => 'harbor-demo.reserveworx.test',
                'fee_payer' => FeePayer::CustomerPaid,
            ],
            [
                'name' => 'Inland Demo',
                'slug' => 'inland-demo',
                'owner' => 'Inland Demo Owner',
                'email' => 'inland-owner@demo.reserveworx.test',
                'hostname' => 'inland-demo.reserveworx.test',
                'fee_payer' => FeePayer::OperatorAbsorbed,
            ],
        ];
    }
}
