<?php

namespace Tests\Feature\Web;

use App\Enums\Industry;
use App\Enums\TenantStatus;
use App\Livewire\Platform\TenantDetail;
use App\Livewire\Platform\Tenants;
use App\Livewire\Staff\NewBooking;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\PlatformAdministrator;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Inventory\ClosureWriter;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\BuildsRentalShop;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class IndustryMasterAdminTest extends TestCase
{
    use BuildsRentalShop, CreatesWorkspace, RefreshDatabase;

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        parent::tearDown();
    }

    public function test_the_product_page_lists_every_industry(): void
    {
        $page = $this->get('http://app.reserveworx.com/')->assertOk()->assertSee('industry-grid', false);

        $this->assertCount(18, Industry::cases());

        foreach (Industry::cases() as $industry) {
            $page->assertSee($industry->label());
            $page->assertSee('/signup?industry='.$industry->value, false);
        }
    }

    public function test_boat_rentals_and_marinas_keep_the_existing_words(): void
    {
        $boats = $this->workspace('boats.test', 'owner', [
            'name' => 'Harbor Boats',
            'industry' => Industry::BoatRentals,
        ]);
        $this->actingAs($boats['user']);

        $this->get($this->tenantUrl('boats.test', '/staff'))
            ->assertOk()
            ->assertSee('Add a location, the boats and gear you rent, and one service with a price. It takes about five minutes.');
        $this->get($this->tenantUrl('boats.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Boats & gear');
        $this->get($this->tenantUrl('boats.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Bookings never take the same boat twice, across rentals and tours.');
        $this->get($this->tenantUrl('boats.test', '/staff/schedule'))
            ->assertOk()
            ->assertSee('Tap an empty hour on a boat or pool to start a booking.');
        $this->get($this->tenantUrl('boats.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Hold boats during checkout for (minutes)');
        $this->get($this->tenantUrl('boats.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('or a single boat for a day or a few hours.');

        $location = Location::factory()->create(['tenant_id' => $boats['tenant']->id, 'name' => 'Harbor']);

        $this->get($this->tenantUrl('boats.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Counted gear, like 12 single kayaks')
            ->assertSee('One tracked item, like a pontoon boat or a guide')
            ->assertSee('Add gear');
        $this->get($this->tenantUrl('boats.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('One boat or pool');
        $this->get($this->tenantUrl('boats.test', '/staff/schedule'))
            ->assertOk()
            ->assertSee('No boats or gear at this location yet.');

        $boats['tenant']->update(['industry' => Industry::BoatsMarinas]);

        $this->get($this->tenantUrl('boats.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Boats & gear')
            ->assertSee('Bookings never take the same boat twice, across rentals and tours.');

        $this->assertClosureError($boats['tenant']->fresh() ?? $boats['tenant'], $location, 'Choose a boat or pool at this location.');
    }

    public function test_a_golf_cart_workspace_uses_cart_words_and_hides_another_tenant(): void
    {
        $golf = $this->workspace('golf.test', 'owner', [
            'name' => 'Fairway Carts',
            'industry' => Industry::GolfCartRentals,
        ]);
        $boats = $this->workspace('boats.test', 'owner', [
            'name' => 'Harbor Boats',
            'industry' => Industry::BoatRentals,
        ]);
        $this->actingAs($golf['user']);

        $this->get($this->tenantUrl('golf.test', '/staff'))
            ->assertOk()
            ->assertSee('Add a location, the carts you rent, and one service with a price. It takes about five minutes.')
            ->assertDontSee('the boats and gear you rent')
            ->assertDontSee('Harbor Boats');
        $this->get($this->tenantUrl('golf.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Golf carts')
            ->assertDontSee('Boats & gear');
        $this->get($this->tenantUrl('golf.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Bookings never take the same cart twice, across rentals and tours.')
            ->assertDontSee('Boats & gear');
        $this->get($this->tenantUrl('golf.test', '/staff/schedule'))
            ->assertOk()
            ->assertSee('Tap an empty hour on a cart or pool to start a booking.')
            ->assertDontSee('Tap an empty hour on a boat or pool to start a booking.');
        $this->get($this->tenantUrl('golf.test', '/staff/settings'))
            ->assertOk()
            ->assertSee('Hold carts during checkout for (minutes)');
        $this->get($this->tenantUrl('golf.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('or a single cart for a day or a few hours.');

        $shop = $this->rentalShop($golf['tenant']);
        app(TenantManager::class)->set($golf['tenant']);

        $this->get($this->tenantUrl('golf.test', '/staff/setup/resources'))
            ->assertOk()
            ->assertSee('Counted gear, like 12 carts')
            ->assertSee('Add cart')
            ->assertDontSee('Boats & gear');
        $this->get($this->tenantUrl('golf.test', '/staff/closures'))
            ->assertOk()
            ->assertSee('One cart or pool');
        $this->get($this->tenantUrl('golf.test', '/staff/businesses/new'))
            ->assertOk()
            ->assertSee('value="golf_cart_rentals" selected', false);

        app(TenantManager::class)->set($golf['tenant']);
        Livewire::test(NewBooking::class)
            ->set('serviceId', $shop['service']->id)
            ->assertSee('Hold these carts')
            ->assertDontSee('Hold these boats');

        $this->assertClosureError($golf['tenant'], $shop['location'], 'Choose a cart or pool at this location.');

        $this->actingAs($boats['user'])
            ->get($this->tenantUrl('boats.test', '/staff/menu'))
            ->assertOk()
            ->assertSee('Boats & gear')
            ->assertDontSee('Golf carts')
            ->assertDontSee('Fairway Carts');
    }

    public function test_master_admin_filters_every_tenant_and_can_change_industry(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Tenant::factory()->create([
            'name' => 'Harbor Boats',
            'slug' => 'harbor-boats',
            'status' => TenantStatus::Active,
            'industry' => Industry::BoatRentals,
        ]);
        Tenant::factory()->create([
            'name' => 'Fairway Carts',
            'slug' => 'fairway-carts',
            'status' => TenantStatus::Suspended,
            'industry' => Industry::GolfCartRentals,
        ]);

        $this->actingAs($admin);
        $page = Livewire::test(Tenants::class)
            ->assertSee('Master admin')
            ->assertSee('Workspaces')
            ->assertSee('Harbor Boats')
            ->assertSee('Fairway Carts')
            ->assertSee('Golf Cart Rentals');
        $html = $page->html();
        $this->assertMatchesRegularExpression('/data-status="active"[^>]*data-count="1"/', $html);
        $this->assertMatchesRegularExpression('/data-status="suspended"[^>]*data-count="1"/', $html);
        $this->assertMatchesRegularExpression('/data-status="onboarding"[^>]*data-count="0"/', $html);

        $page->set('industryFilter', Industry::GolfCartRentals->value)
            ->assertSee('Fairway Carts')
            ->assertDontSee('Harbor Boats')
            ->set('status', TenantStatus::Active->value)
            ->assertSee('No workspaces match.')
            ->call('clearFilters')
            ->assertSee('Harbor Boats')
            ->set('name', 'Cedar Carts')
            ->assertSet('slug', 'cedar-carts')
            ->set('industry', Industry::GolfCartRentals->value)
            ->call('create')
            ->assertHasNoErrors();

        $created = Tenant::query()->where('slug', 'cedar-carts')->firstOrFail();
        $this->assertSame(Industry::GolfCartRentals, $created->industry);
        $this->assertSame(TenantStatus::Onboarding, $created->status);

        Livewire::test(TenantDetail::class, ['tenant' => $created->id])
            ->set('industry', Industry::EBikes->value)
            ->call('saveIndustry')
            ->assertHasNoErrors()
            ->assertSee('Industry changed to E-Bikes.');

        $this->assertSame(Industry::EBikes, $created->fresh()?->industry);
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.industry_updated')->count());

        Livewire::test(TenantDetail::class, ['tenant' => $created->id])
            ->call('saveIndustry')
            ->assertSee('Industry is already E-Bikes.');
        $this->assertSame(1, AuditLog::withoutGlobalScope(TenantScope::class)->where('action', 'tenant.industry_updated')->count());

        $owner = $this->workspace('owner.test');
        $this->actingAs($owner['user']);
        Livewire::test(Tenants::class)->assertForbidden();
        Livewire::test(TenantDetail::class, ['tenant' => $created->id])->assertForbidden();
    }

    public function test_signup_and_the_api_accept_an_industry_and_default_when_it_is_omitted(): void
    {
        Notification::fake();
        RateLimiter::clear('signup|127.0.0.1');

        $this->get('http://app.reserveworx.com/signup?industry=golf_cart_rentals')
            ->assertOk()
            ->assertSee('value="golf_cart_rentals" selected', false);

        $this->post('http://app.reserveworx.com/signup', $this->signupPayload([
            'business_name' => 'Fairway Carts',
            'slug' => 'fairway',
            'email' => 'ada@example.com',
            'industry' => Industry::GolfCartRentals->value,
        ]))->assertRedirect();
        $this->assertSame(Industry::GolfCartRentals, Tenant::query()->where('slug', 'fairway')->firstOrFail()->industry);

        $this->post('http://app.reserveworx.com/signup', $this->signupPayload([
            'business_name' => 'Harbor Boats',
            'slug' => 'harbor',
            'email' => 'grace@example.com',
        ]))->assertRedirect();
        $this->assertSame(Industry::BoatRentals, Tenant::query()->where('slug', 'harbor')->firstOrFail()->industry);

        $this->from('http://app.reserveworx.com/signup')
            ->post('http://app.reserveworx.com/signup', $this->signupPayload([
                'slug' => 'spaceships',
                'email' => 'nope@example.com',
                'industry' => 'spaceships',
            ]))
            ->assertSessionHasErrors('industry');

        $owner = $this->workspace('owner.test');
        $this->actingAs($owner['user'])
            ->post($this->tenantUrl('owner.test', '/staff/businesses'), [
                'name' => 'Cart Co',
                'slug' => 'cart-co',
                'industry' => Industry::GolfCartRentals->value,
            ])
            ->assertRedirect();
        $this->assertSame(Industry::GolfCartRentals, Tenant::query()->where('slug', 'cart-co')->firstOrFail()->industry);

        $this->post($this->tenantUrl('owner.test', '/staff/businesses'), [
            'name' => 'Second Boats',
            'slug' => 'second-boats',
        ])->assertRedirect();
        $this->assertSame(Industry::BoatRentals, Tenant::query()->where('slug', 'second-boats')->firstOrFail()->industry);

        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        Sanctum::actingAs($admin);

        $this->postJson('http://localhost/api/v1/platform/tenants', [
            'name' => 'Api Boats',
            'slug' => 'api-boats',
        ])->assertCreated()->assertJsonPath('data.industry', 'boat_rentals');

        $this->postJson('http://localhost/api/v1/platform/tenants', [
            'name' => 'Api Carts',
            'slug' => 'api-carts',
            'industry' => Industry::GolfCartRentals->value,
        ])->assertCreated()->assertJsonPath('data.industry', 'golf_cart_rentals');

        $this->postJson('http://localhost/api/v1/platform/tenants', [
            'name' => 'Api Bad',
            'slug' => 'api-bad',
            'industry' => 'spaceships',
        ])->assertStatus(422);
    }

    private function assertClosureError(Tenant $tenant, Location $location, string $message): void
    {
        try {
            app(ClosureWriter::class)->save($tenant, [
                'location_id' => $location->id,
                'scope' => 'resource',
                'resource_key' => 'missing',
                'reason' => 'Closed',
                'span' => 'day',
                'yearly' => false,
                'starts_on' => '2026-07-01',
                'ends_on' => '2026-07-01',
            ]);
            $this->fail('A missing resource should be refused.');
        } catch (ValidationException $exception) {
            $this->assertSame($message, $exception->errors()['resource_key'][0] ?? null);
        }
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function signupPayload(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Lakeside Paddle',
            'slug' => 'lakeside',
            'owner_name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'accepted_terms' => '1',
            'company_url' => '',
        ], $overrides);
    }
}
