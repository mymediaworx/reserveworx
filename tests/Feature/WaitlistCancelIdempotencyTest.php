<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Domain;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Models\WaitlistMutation;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class WaitlistCancelIdempotencyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_the_same_waitlist_cancel_key_returns_the_first_cancellation(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $money = $this->money($workspace['tenant']);
        $entryId = $this->join($service, 'wait-cancel');
        $otherId = $this->join($service, 'wait-other');
        $plainId = $this->join($service, 'wait-plain');
        $longId = $this->join($service, 'wait-long');
        $url = $this->tenantUrl('dock.test', '/api/v1/waitlist/'.$entryId.'/cancel');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/waitlist/'.$longId.'/cancel'), [], [
            'Idempotency-Key' => str_repeat('k', 81),
        ])->assertStatus(422)->assertJsonValidationErrors('idempotency_key');

        $first = $this->postJson($url, [], [
            'Idempotency-Key' => 'cancel-once',
        ])->assertOk()
            ->assertJsonPath('data.id', $entryId)
            ->assertJsonPath('data.status', 'cancelled')
            ->json('data');
        $this->assertIsArray($first);

        $this->postJson($url, ['idempotency_key' => 'cancel-once'])
            ->assertOk()
            ->assertJsonPath('data.id', $entryId)
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/waitlist/'.$otherId.'/cancel'), [], [
            'Idempotency-Key' => 'cancel-once',
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/waitlist/'.$plainId.'/cancel'))->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/waitlist/'.$plainId.'/cancel'))->assertOk();

        $exact = str_repeat('w', 80);
        $this->postJson($this->tenantUrl('dock.test', '/api/v1/waitlist/'.$longId.'/cancel'), [], [
            'Idempotency-Key' => $exact,
        ])->assertOk()
            ->assertJsonPath('data.id', $longId)
            ->assertJsonPath('data.status', 'cancelled');

        $other = $this->workspace('other.test');
        $this->actingAsWorkspace($other);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/waitlist/'.$entryId.'/cancel'), [], [
            'Idempotency-Key' => 'cancel-from-other',
        ])->assertNotFound();

        $this->actingAsWorkspace($workspace);
        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame('cancelled', WaitlistEntry::query()->findOrFail($entryId)->status->value);
        $this->assertSame('waiting', WaitlistEntry::query()->findOrFail($otherId)->status->value);
        $this->assertSame('cancelled', WaitlistEntry::query()->findOrFail($plainId)->status->value);
        $this->assertSame('cancelled', WaitlistEntry::query()->findOrFail($longId)->status->value);
        $this->assertSame(1, AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $entryId)->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $otherId)->count());
        $this->assertSame(2, AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $plainId)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $longId)->count());
        $this->assertSame(
            ['cancel-once', $exact],
            WaitlistMutation::query()->where('action', 'cancel')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
        );
        $this->assertSame(0, WaitlistMutation::query()->where('waitlist_entry_id', $plainId)->count());
        $this->assertSame($money, $this->money($workspace['tenant']));
        app(TenantManager::class)->forget();

        app(TenantManager::class)->set($other['tenant']);
        $this->assertSame(0, WaitlistEntry::query()->count());
        $this->assertSame(0, WaitlistMutation::query()->count());
        app(TenantManager::class)->forget();
    }

    public function test_the_header_key_wins_over_the_body_key(): void
    {
        [$workspace, $service] = $this->rental();
        $this->actingAsWorkspace($workspace);
        $entryId = $this->join($service, 'wait-header');
        $url = $this->tenantUrl('dock.test', '/api/v1/waitlist/'.$entryId.'/cancel');

        $this->postJson($url, [
            'idempotency_key' => 'body-key',
        ], [
            'Idempotency-Key' => 'header-key',
        ])->assertOk()
            ->assertJsonPath('data.id', $entryId)
            ->assertJsonPath('data.status', 'cancelled');

        $this->postJson($url, [], [
            'Idempotency-Key' => 'header-key',
        ])->assertOk()->assertJsonPath('data.id', $entryId);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(1, AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $entryId)->count());
        app(TenantManager::class)->forget();

        $this->postJson($url, [
            'idempotency_key' => 'body-key',
        ])->assertOk()->assertJsonPath('data.id', $entryId);

        app(TenantManager::class)->set($workspace['tenant']);
        $this->assertSame(2, AuditLog::query()->where('action', 'waitlist.cancelled')->where('subject_id', $entryId)->count());
        $this->assertSame(
            ['body-key', 'header-key'],
            WaitlistMutation::query()->where('action', 'cancel')->orderBy('idempotency_key')->pluck('idempotency_key')->all(),
        );
        app(TenantManager::class)->forget();
    }

    /**
     * @return array{prices: int, payments: int, refunds: int, fees: int, fee_refunds: int, taxes: int}
     */
    private function money(Tenant $tenant): array
    {
        app(TenantManager::class)->set($tenant);

        $counts = [
            'prices' => PriceRule::query()->count(),
            'payments' => Payment::query()->count(),
            'refunds' => Refund::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'fee_refunds' => PlatformFeeRefund::query()->count(),
            'taxes' => LocationTaxRule::query()->count(),
        ];

        app(TenantManager::class)->forget();

        return $counts;
    }

    private function join(Service $service, string $key): string
    {
        $id = $this->postJson($this->tenantUrl('dock.test', '/api/v1/waitlist'), [
            'service_id' => $service->id,
            'starts_at' => '2026-06-16T15:00:00Z',
            'ends_at' => '2026-06-16T16:00:00Z',
            'participants' => 2,
            'customer_name' => 'Ada Kayak',
            'customer_email' => $key.'@example.com',
            'idempotency_key' => $key,
        ])->assertStatus(202)->assertJsonPath('data.status', 'waiting')->json('data.id');
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @return array{0: array{tenant: Tenant, domain: Domain, user: User, membership: Membership}, 1: Service}
     */
    private function rental(): array
    {
        $workspace = $this->workspace('dock.test');
        $location = Location::factory()->create([
            'tenant_id' => $workspace['tenant']->id,
            'timezone' => 'UTC',
        ]);
        app(TenantManager::class)->set($workspace['tenant']);
        $service = Service::query()->create([
            'tenant_id' => $workspace['tenant']->id,
            'location_id' => $location->id,
            'name' => 'Hourly boat',
            'kind' => 'rental',
            'waitlist_enabled' => true,
        ]);
        app(TenantManager::class)->forget();

        return [$workspace, $service];
    }
}
