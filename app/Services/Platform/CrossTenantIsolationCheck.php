<?php

namespace App\Services\Platform;

use App\Enums\Industry;
use App\Enums\TenantStatus;
use App\Models\Customer;
use App\Models\Tenant;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Local proof that one workspace cannot read another's customer, and that a
 * request with no workspace cannot either. Spec Section 12 and Section 17.
 * A sample customer is written and rolled back. Nothing here calls Stripe.
 */
final class CrossTenantIsolationCheck
{
    public const PROBE_MARK = 'rw-isolation-probe-';

    /**
     * @param  (Closure(string): bool)|null  $otherTenantSees
     * @param  (Closure(string): bool)|null  $noContextSees
     * @return array{isolated: bool, checks: list<array{key: string, label: string, state: string, detail: string, line: string}>}
     */
    public function build(?Closure $otherTenantSees = null, ?Closure $noContextSees = null): array
    {
        $probe = $this->probe($otherTenantSees, $noContextSees);
        $checks = [
            $this->seen(
                'other_tenant',
                'Other workspace',
                $probe['other'],
                'Another workspace cannot read this workspace\'s customer.',
                'Another workspace can read this workspace\'s customer.',
            ),
            $this->seen(
                'no_context',
                'No workspace',
                $probe['none'],
                'A request with no workspace cannot read a customer.',
                'A request with no workspace can read a customer.',
            ),
        ];

        $isolated = collect($checks)->every(fn (array $check): bool => $check['state'] !== 'fail');

        return [
            'isolated' => $isolated,
            'checks' => $checks,
        ];
    }

    /**
     * @param  (Closure(string): bool)|null  $otherTenantSees
     * @param  (Closure(string): bool)|null  $noContextSees
     * @return array{other: ?bool, none: ?bool}
     */
    private function probe(?Closure $otherTenantSees, ?Closure $noContextSees): array
    {
        $manager = app(TenantManager::class);
        $previous = $manager->get();
        $connection = DB::connection();
        $level = $connection->transactionLevel();
        $result = ['other' => null, 'none' => null];

        try {
            $connection->beginTransaction();
            $suffix = Str::lower(Str::random(8));
            $first = Tenant::query()->create([
                'name' => 'Isolation check',
                'slug' => 'isolation-a-'.$suffix,
                'status' => TenantStatus::Onboarding,
                'industry' => Industry::BoatRentals,
            ]);
            $second = Tenant::query()->create([
                'name' => 'Isolation check',
                'slug' => 'isolation-b-'.$suffix,
                'status' => TenantStatus::Onboarding,
                'industry' => Industry::BoatRentals,
            ]);
            $manager->set($first);
            $email = self::PROBE_MARK.Str::lower(Str::random(16)).'@invalid.test';
            Customer::query()->create([
                'tenant_id' => $first->id,
                'name' => 'Isolation probe',
                'email' => $email,
            ]);
            $result['other'] = $otherTenantSees !== null
                ? $otherTenantSees($email)
                : $this->visibleTo($second, $email);
            $result['none'] = $noContextSees !== null
                ? $noContextSees($email)
                : $this->visibleWithoutContext($email);
        } catch (Throwable) {
            $result = ['other' => null, 'none' => null];
        } finally {
            while (DB::connection()->transactionLevel() > $level) {
                DB::connection()->rollBack();
            }

            if ($previous instanceof Tenant) {
                $manager->set($previous);
            } else {
                $manager->forget();
            }
        }

        return $result;
    }

    private function visibleTo(Tenant $tenant, string $email): bool
    {
        app(TenantManager::class)->set($tenant);

        return Customer::query()->where('email', $email)->exists();
    }

    private function visibleWithoutContext(string $email): bool
    {
        app(TenantManager::class)->forget();

        return Customer::query()->where('email', $email)->exists();
    }

    /**
     * True means the customer was visible, which fails the check.
     *
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function seen(string $key, string $label, ?bool $visible, string $pass, string $fail): array
    {
        if ($visible === null) {
            return $this->check($key, $label, 'fail', 'The cross-tenant check could not be run.');
        }

        return $this->check($key, $label, $visible ? 'fail' : 'pass', $visible ? $fail : $pass);
    }

    /**
     * @return array{key: string, label: string, state: string, detail: string, line: string}
     */
    private function check(string $key, string $label, string $state, string $detail): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'state' => $state,
            'detail' => $detail,
            'line' => $state,
        ];
    }
}
