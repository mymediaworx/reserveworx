<?php

namespace Tests\Feature\Disclosures;

use App\Domain\Fees\Disclosures\BookingFeeRefundTerms;
use App\Domain\Fees\Disclosures\DisclosurePublisher;
use App\Enums\DisclosureKind;
use App\Enums\FeePayer;
use App\Models\DisclosureVersion;
use App\Models\Tenant;
use App\Tenancy\MissingTenantContextException;
use App\Tenancy\TenantContextException;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class DisclosureVersionTest extends TestCase
{
    use RefreshDatabase;

    private function publishFor(Tenant $tenant, FeePayer $payer = FeePayer::CustomerPaid): ?DisclosureVersion
    {
        app(TenantManager::class)->set($tenant);

        return app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($tenant, $payer);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // Cross-tenant isolation
    // ---------------------------------------------------------------

    public function test_a_tenant_sees_only_its_own_disclosure_versions(): void
    {
        $alpha = Tenant::factory()->create(['name' => 'Alpha Kayaks']);
        $beta = Tenant::factory()->create(['name' => 'Beta Bikes']);
        $alphaVersion = $this->publishFor($alpha);
        $betaVersion = $this->publishFor($beta);

        app(TenantManager::class)->set($alpha);

        $this->assertSame([$alphaVersion?->id], DisclosureVersion::query()->pluck('id')->all());
        $this->assertNull(DisclosureVersion::query()->find($betaVersion?->id));
        $this->assertStringNotContainsString('Beta Bikes', (string) DisclosureVersion::query()->value('body'));
    }

    public function test_no_tenant_context_matches_nothing(): void
    {
        $this->publishFor(Tenant::factory()->create());
        app(TenantManager::class)->forget();

        $this->assertSame(0, DisclosureVersion::query()->count());
        $this->assertSame(1, DisclosureVersion::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_creating_a_version_without_tenant_context_fails(): void
    {
        $this->expectException(MissingTenantContextException::class);

        DisclosureVersion::create([
            'kind' => DisclosureKind::BookingFeeRefundTerms,
            'version' => 1,
            'fee_payer' => FeePayer::CustomerPaid,
            'rules_version' => BookingFeeRefundTerms::RULES_VERSION,
            'body' => 'x',
            'content_hash' => str_repeat('0', 64),
            'published_at' => now(),
        ]);
    }

    public function test_publishing_for_another_tenant_than_the_context_is_refused(): void
    {
        $alpha = Tenant::factory()->create();
        $beta = Tenant::factory()->create();
        app(TenantManager::class)->set($alpha);

        $this->expectException(TenantContextException::class);
        app(DisclosurePublisher::class)->currentBookingFeeRefundTerms($beta, FeePayer::CustomerPaid);
    }

    public function test_version_numbers_are_per_tenant(): void
    {
        $alpha = Tenant::factory()->create(['name' => 'Alpha Kayaks']);
        $beta = Tenant::factory()->create(['name' => 'Beta Bikes']);

        $this->publishFor($alpha);
        $alpha->update(['name' => 'Alpha Kayak Tours']);
        $alphaV2 = $this->publishFor($alpha);
        $betaV1 = $this->publishFor($beta);

        $this->assertSame(2, $alphaV2?->version);
        $this->assertSame($alpha->id, $alphaV2?->tenant_id);
        $this->assertSame(1, $betaV1?->version);
        $this->assertSame($beta->id, $betaV1?->tenant_id);
    }

    // ---------------------------------------------------------------
    // Versioning
    // ---------------------------------------------------------------

    public function test_unchanged_text_reuses_the_current_version(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Lake Paddle Co']);

        $first = $this->publishFor($tenant);
        $second = $this->publishFor($tenant);

        $this->assertSame($first?->id, $second?->id);
        $this->assertSame(1, DisclosureVersion::query()->count());
    }

    public function test_changed_text_publishes_a_new_version_and_keeps_the_old_one(): void
    {
        $tenant = Tenant::factory()->create(['name' => 'Lake Paddle Co']);

        $v1 = $this->publishFor($tenant);
        $tenant->update(['name' => 'Lake Paddle Company']);
        $v2 = $this->publishFor($tenant);

        $this->assertSame([1, 2], DisclosureVersion::query()->orderBy('version')->pluck('version')->all());
        $this->assertStringContainsString('Lake Paddle Co cancels', (string) $v1?->fresh()?->body);
        $this->assertStringContainsString('Lake Paddle Company cancels', (string) $v2?->body);
        $this->assertSame(BookingFeeRefundTerms::RULES_VERSION, $v2?->rules_version);
        $this->assertSame(FeePayer::CustomerPaid, $v2?->fee_payer);
        $this->assertCount(7, $v2->lines());
    }

    public function test_operator_absorbed_tenants_have_no_fee_refund_terms(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertNull($this->publishFor($tenant, FeePayer::OperatorAbsorbed));
        $this->assertSame(0, DisclosureVersion::query()->count());
    }

    public function test_versions_cannot_be_edited(): void
    {
        $version = $this->publishFor(Tenant::factory()->create());

        $this->expectException(LogicException::class);
        $version?->update(['body' => 'Different terms']);
    }

    public function test_versions_cannot_be_deleted(): void
    {
        $version = $this->publishFor(Tenant::factory()->create());

        $this->expectException(LogicException::class);
        $version?->delete();
    }
}
