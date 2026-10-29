<?php

namespace Tests\Feature\Payments;

use App\Domain\Payments\StripeGateway;
use App\Models\AuditLog;
use App\Models\LocationTaxRule;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\PlatformFeeRefund;
use App\Models\PriceRule;
use App\Models\PricingSnapshot;
use App\Models\Refund;
use App\Models\StripeAccount;
use App\Models\StripeConnectRequest;
use App\Services\Payments\FakeStripeGateway;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StripeConnectIdempotencyTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_a_refused_connect_request_does_not_keep_the_key(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);
        $url = $this->tenantUrl('dock.test', '/api/v1/stripe/account');
        $payload = $this->payload('Harbor Co', 'owner@harbor.test');
        $before = $this->counts();

        $this->postJson($url, $payload, [
            'Idempotency-Key' => str_repeat('b', 81),
        ])->assertStatus(422);

        $this->postJson($url, [
            'email' => 'owner@harbor.test',
            'refresh_url' => 'https://dock.test/staff/settings',
            'return_url' => 'https://dock.test/staff/settings',
            'idempotency_key' => 'missing-name',
        ])->assertStatus(422);

        $this->postJson($url, $payload, [
            'Idempotency-Key' => str_repeat('a', 80),
        ])->assertStatus(409)
            ->assertJsonPath('message', 'Stripe is not configured. Add the platform secret before taking online payments.');

        $this->assertSame(0, StripeAccount::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, StripeConnectRequest::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(0, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'stripe.account_linked')->count());
        $this->assertSame($before, $this->counts());
    }

    public function test_the_same_connect_key_returns_the_first_account_and_does_not_open_a_second_link(): void
    {
        $workspace = $this->workspace('dock.test');
        $other = $this->workspace('other.test');
        $fake = new FakeStripeGateway;
        $this->app->instance(StripeGateway::class, $fake);
        $this->actingAsWorkspace($workspace);
        $url = $this->tenantUrl('dock.test', '/api/v1/stripe/account');
        $key = str_repeat('a', 80);
        $payload = $this->payload('Harbor Co', 'owner@harbor.test');
        $before = $this->counts();

        $first = $this->postJson($url, $payload + ['idempotency_key' => 'body-key'], [
            'Idempotency-Key' => $key,
        ])->assertOk();
        $accountId = $first->json('data.stripe_account_id');
        $this->assertIsString($accountId);
        $this->assertSame('acct_test_123', $accountId);
        $this->assertSame('pending', $first->json('data.card_payments_status'));
        $this->assertSame('https://connect.stripe.test/setup/acct_test_123', $first->json('data.onboarding_url'));

        $this->postJson($url, $payload + ['idempotency_key' => 'body-key'], [
            'Idempotency-Key' => $key,
        ])->assertOk()
            ->assertJsonPath('data.stripe_account_id', $accountId)
            ->assertJsonPath('data.onboarding_url', null);

        $this->postJson($url, $this->payload('Harbor Co', 'other@harbor.test'), [
            'Idempotency-Key' => $key,
        ])->assertStatus(409)
            ->assertJsonPath('message', 'That idempotency key was already used for a different request.');

        $secondKey = $this->postJson($url, $payload + [
            'idempotency_key' => 'body-key',
        ])->assertOk();
        $this->assertSame($accountId, $secondKey->json('data.stripe_account_id'));
        $this->assertSame('https://connect.stripe.test/setup/acct_test_123', $secondKey->json('data.onboarding_url'));

        $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('data.onboarding_url', 'https://connect.stripe.test/setup/acct_test_123');
        $this->postJson($url, $payload)->assertOk()
            ->assertJsonPath('data.onboarding_url', 'https://connect.stripe.test/setup/acct_test_123');

        $this->actingAsWorkspace($workspace);
        $this->postJson($this->tenantUrl('other.test', '/api/v1/stripe/account'), $payload, [
            'Idempotency-Key' => $key,
        ])->assertForbidden();

        $this->assertSame(1, StripeAccount::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(1, count($fake->accounts));
        $this->assertSame(4, count($fake->onboardingLinks));
        $this->assertSame(2, StripeConnectRequest::withoutGlobalScope(TenantScope::class)->count());
        $this->assertSame(4, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'stripe.account_linked')->count());
        $this->assertSame(0, StripeAccount::withoutGlobalScope(TenantScope::class)->where('tenant_id', $other['tenant']->id)->count());

        $stored = StripeConnectRequest::withoutGlobalScope(TenantScope::class)->where('idempotency_key', $key)->firstOrFail();
        $encoded = json_encode($stored->getAttributes());
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('owner@harbor.test', $encoded);
        $this->assertStringNotContainsString('connect.stripe.test', $encoded);
        $this->assertSame($before, $this->counts());
    }

    /**
     * @return array{display_name: string, email: string, refresh_url: string, return_url: string}
     */
    private function payload(string $name, string $email): array
    {
        return [
            'display_name' => $name,
            'email' => $email,
            'refresh_url' => 'https://dock.test/staff/settings',
            'return_url' => 'https://dock.test/staff/settings',
        ];
    }

    /**
     * @return array{payments: int, refunds: int, fees: int, fee_refunds: int, snapshots: int, prices: int, taxes: int}
     */
    private function counts(): array
    {
        return [
            'payments' => Payment::withoutGlobalScope(TenantScope::class)->count(),
            'refunds' => Refund::withoutGlobalScope(TenantScope::class)->count(),
            'fees' => PlatformFee::withoutGlobalScope(TenantScope::class)->count(),
            'fee_refunds' => PlatformFeeRefund::withoutGlobalScope(TenantScope::class)->count(),
            'snapshots' => PricingSnapshot::withoutGlobalScope(TenantScope::class)->count(),
            'prices' => PriceRule::withoutGlobalScope(TenantScope::class)->count(),
            'taxes' => LocationTaxRule::withoutGlobalScope(TenantScope::class)->count(),
        ];
    }
}
