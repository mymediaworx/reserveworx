<?php

namespace Tests\Feature\Web;

use App\Livewire\Staff\Setup\Resources;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\LocationTaxRule;
use App\Models\Membership;
use App\Models\MembershipLocation;
use App\Models\Payment;
use App\Models\PlatformFee;
use App\Models\Pool;
use App\Models\PriceRule;
use App\Models\Refund;
use App\Models\ResourceCategory;
use App\Tenancy\StaffLocationAccess;
use App\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class StaffAssetTypeTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_an_owner_adds_a_type_and_assigns_it_without_changing_money(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $boat = Asset::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $shop['location']->id,
            'name' => 'Pontoon',
        ]);
        $this->actingAs($alpha['user']);
        $money = $this->moneyCounts();

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Asset types')
            ->assertSee('No asset types yet.')
            ->assertSee('Add asset type');

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(Resources::class)
            ->set('categoryName', '  Single kayak  ')
            ->call('createCategory')
            ->assertHasNoErrors()
            ->assertSee('Single kayak');

        $category = ResourceCategory::query()->where('name', 'Single kayak')->sole();
        $this->assertSame('single-kayak', $category->slug);
        $this->assertSame($alpha['tenant']->id, $category->tenant_id);
        $this->assertTrue(AuditLog::query()->where('action', 'resource_category.created')->where('subject_id', $category->id)->exists());

        Livewire::test(Resources::class)
            ->set('categoryName', 'single kayak')
            ->call('createCategory')
            ->assertHasErrors('categoryName');

        $this->assertSame(1, ResourceCategory::query()->count());

        Livewire::test(Resources::class)
            ->call('setCategory', 'pool', $shop['pool']->id, $category->id)
            ->assertHasNoErrors()
            ->call('setCategory', 'pool', $shop['pool']->id, $category->id)
            ->call('setCategory', 'asset', $boat->id, $category->id)
            ->assertHasNoErrors();

        $this->assertSame($category->id, $shop['pool']->fresh()->resource_category_id);
        $this->assertSame($category->id, $boat->fresh()->resource_category_id);
        $this->assertSame(1, AuditLog::query()->where('action', 'pool.updated')->where('subject_id', $shop['pool']->id)->count());
        $this->assertSame(1, AuditLog::query()->where('action', 'asset.updated')->where('subject_id', $boat->id)->count());

        Livewire::test(Resources::class)
            ->set('kind', 'asset')
            ->set('locationId', $shop['location']->id)
            ->set('name', 'North pontoon')
            ->set('capacity', 6)
            ->set('categoryId', $category->id)
            ->call('create')
            ->assertHasNoErrors();

        $created = Asset::query()->where('name', 'North pontoon')->sole();
        $this->assertSame($category->id, $created->resource_category_id);
        $this->assertSame(6, $created->capacity);

        Livewire::test(Resources::class)
            ->call('setCategory', 'pool', $shop['pool']->id, '')
            ->assertHasNoErrors();

        $this->assertNull($shop['pool']->fresh()->resource_category_id);
        $this->assertSame(1, ResourceCategory::query()->count());
        $this->assertSame($money, $this->moneyCounts());
    }

    public function test_a_blank_symbol_or_overlong_type_name_is_refused(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->set('categoryName', '   ')
            ->call('createCategory')
            ->assertHasErrors('categoryName')
            ->set('categoryName', '!!!')
            ->call('createCategory')
            ->assertHasErrors('categoryName')
            ->set('categoryName', str_repeat('a', 121))
            ->call('createCategory')
            ->assertHasErrors('categoryName');

        $this->assertSame(0, ResourceCategory::query()->count());
        $this->assertSame(0, AuditLog::query()->where('action', 'resource_category.created')->count());

        Livewire::test(Resources::class)
            ->set('categoryName', str_repeat('b', 120))
            ->call('createCategory')
            ->assertHasNoErrors();

        $kept = ResourceCategory::query()->sole();
        $this->assertSame(120, strlen($kept->name));
        $this->assertSame(64, strlen($kept->slug));
    }

    public function test_staff_can_see_the_type_and_cannot_change_it(): void
    {
        $alpha = $this->workspace('alpha.test', 'staff');
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Single kayak',
            'slug' => 'single-kayak',
        ]);
        $shop['pool']->update(['resource_category_id' => $category->id]);
        $this->actingAs($alpha['user']);

        $this->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Single kayak')
            ->assertDontSee('Add asset type', false);

        app(TenantManager::class)->set($alpha['tenant']);

        Livewire::test(Resources::class)
            ->set('categoryName', 'Tandem')
            ->call('createCategory')
            ->assertForbidden();

        Livewire::test(Resources::class)
            ->call('setCategory', 'pool', $shop['pool']->id, '')
            ->assertForbidden();

        $this->assertSame($category->id, $shop['pool']->fresh()->resource_category_id);
        $this->assertSame(0, ResourceCategory::query()->where('name', 'Tandem')->count());
    }

    public function test_a_member_limited_to_another_location_cannot_change_the_type(): void
    {
        $alpha = $this->workspace('alpha.test', 'manager');
        app(TenantManager::class)->set($alpha['tenant']);
        $north = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $south = Location::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'South dock',
            'timezone' => 'UTC',
        ]);
        $southPool = Pool::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'location_id' => $south->id,
            'name' => 'South kayaks',
        ]);
        $category = ResourceCategory::factory()->create([
            'tenant_id' => $alpha['tenant']->id,
            'name' => 'Single kayak',
            'slug' => 'single-kayak',
        ]);
        $this->limit($alpha['membership'], $north['location']->id);
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->call('setCategory', 'pool', $southPool->id, $category->id)
            ->assertNotFound();

        $this->assertNull($southPool->fresh()->resource_category_id);

        Livewire::test(Resources::class)
            ->call('setCategory', 'pool', $north['pool']->id, $category->id)
            ->assertHasNoErrors();

        $this->assertSame($category->id, $north['pool']->fresh()->resource_category_id);
    }

    public function test_another_workspace_type_cannot_be_used(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        app(TenantManager::class)->set($beta['tenant']);
        $foreignCategory = ResourceCategory::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'name' => 'Beta kayaks',
            'slug' => 'beta-kayaks',
        ]);
        $foreignPool = Pool::factory()->create([
            'tenant_id' => $beta['tenant']->id,
            'location_id' => Location::factory()->create(['tenant_id' => $beta['tenant']->id])->id,
            'name' => 'Beta pool',
        ]);
        app(TenantManager::class)->set($alpha['tenant']);
        $shop = $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $this->actingAs($alpha['user']);

        Livewire::test(Resources::class)
            ->call('setCategory', 'pool', $shop['pool']->id, $foreignCategory->id)
            ->assertHasErrors('pool-'.$shop['pool']->id.'-category');

        Livewire::test(Resources::class)
            ->call('setCategory', 'pool', $foreignPool->id, $foreignCategory->id)
            ->assertNotFound();

        Livewire::test(Resources::class)
            ->set('kind', 'pool')
            ->set('locationId', $shop['location']->id)
            ->set('name', 'Borrowed')
            ->set('quantity', 2)
            ->set('categoryId', $foreignCategory->id)
            ->call('create')
            ->assertHasErrors('categoryId');

        $this->assertNull($shop['pool']->fresh()->resource_category_id);
        $this->assertSame(0, Pool::query()->where('name', 'Borrowed')->count());
        app(TenantManager::class)->set($beta['tenant']);
        $this->assertNull($foreignPool->fresh()->resource_category_id);
        $this->assertSame($foreignCategory->id, ResourceCategory::query()->where('name', 'Beta kayaks')->value('id'));
    }

    public function test_the_type_controls_wrap_on_a_narrow_screen(): void
    {
        $alpha = $this->workspace('alpha.test');
        app(TenantManager::class)->set($alpha['tenant']);
        $this->rentalShop($alpha['tenant'], 1, 'UTC');
        $this->actingAs($alpha['user']);

        $html = (string) $this->get($this->tenantUrl('alpha.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('id="asset-types"', false)
            ->assertSee('@media (max-width: 390px)', false)
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 390px\) \{.*?\.resources-page \.resource-type,.*?width: 100%;.*?\.resources-page #asset-types \* \{ overflow-wrap: anywhere; \}/s',
            $html,
        );
    }

    /**
     * @return array{payments: int, fees: int, refunds: int, prices: int, taxes: int}
     */
    private function moneyCounts(): array
    {
        return [
            'payments' => Payment::query()->count(),
            'fees' => PlatformFee::query()->count(),
            'refunds' => Refund::query()->count(),
            'prices' => PriceRule::query()->count(),
            'taxes' => LocationTaxRule::query()->count(),
        ];
    }

    private function limit(Membership $membership, string $locationId): void
    {
        $membership->locations_restricted = true;
        $membership->save();
        MembershipLocation::query()->create([
            'tenant_id' => $membership->tenant_id,
            'membership_id' => $membership->id,
            'location_id' => $locationId,
        ]);
        app(StaffLocationAccess::class)->forget();
    }
}
