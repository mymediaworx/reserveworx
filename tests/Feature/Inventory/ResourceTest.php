<?php

namespace Tests\Feature\Inventory;

use App\Models\Asset;
use App\Models\Location;
use App\Models\Pool;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class ResourceTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    public function test_an_owner_can_create_a_location_asset_and_pool(): void
    {
        $workspace = $this->workspace('dock.test');
        $this->actingAsWorkspace($workspace);

        $location = $this->postJson($this->tenantUrl('dock.test', '/api/v1/locations'), [
            'name' => 'Harbor',
            'timezone' => 'America/New_York',
        ])->assertCreated()->json('data');

        $this->assertSame('America/New_York', $location['timezone']);

        $category = $this->postJson($this->tenantUrl('dock.test', '/api/v1/resource-categories'), [
            'name' => 'Kayaks',
            'slug' => 'kayaks',
        ])->assertCreated()->json('data');

        $asset = $this->postJson($this->tenantUrl('dock.test', '/api/v1/assets'), [
            'location_id' => $location['id'],
            'resource_category_id' => $category['id'],
            'name' => 'Boat A',
            'capacity' => 6,
            'prep_buffer_minutes' => 15,
            'return_buffer_minutes' => 15,
        ])->assertCreated()->json('data');

        $pool = $this->postJson($this->tenantUrl('dock.test', '/api/v1/pools'), [
            'location_id' => $location['id'],
            'resource_category_id' => $category['id'],
            'name' => 'Single kayaks',
            'quantity' => 20,
        ])->assertCreated()->json('data');

        $this->assertSame(20, $pool['quantity']);
        $this->assertSame($workspace['tenant']->id, Asset::withoutGlobalScope(TenantScope::class)->findOrFail($asset['id'])->tenant_id);
        $this->assertSame($workspace['tenant']->id, Pool::withoutGlobalScope(TenantScope::class)->findOrFail($pool['id'])->tenant_id);

        $this->patchJson($this->tenantUrl('dock.test', '/api/v1/assets/'.$asset['id']), [
            'status' => 'maintenance',
        ])->assertOk()->assertJsonPath('data.status', 'maintenance');
    }

    public function test_staff_can_view_resources_and_cannot_create_them(): void
    {
        $workspace = $this->workspace('dock.test', 'staff');
        $this->actingAsWorkspace($workspace);
        $location = Location::factory()->create(['tenant_id' => $workspace['tenant']->id]);

        $this->getJson($this->tenantUrl('dock.test', '/api/v1/locations'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $location->id);

        $this->postJson($this->tenantUrl('dock.test', '/api/v1/locations'), [
            'name' => 'Other',
            'timezone' => 'UTC',
        ])->assertForbidden();
    }

    public function test_a_resource_from_another_workspace_is_not_found(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $foreign = Location::factory()->create(['tenant_id' => $beta['tenant']->id, 'name' => 'Hidden']);
        $this->actingAsWorkspace($alpha);

        $this->getJson($this->tenantUrl('alpha.test', '/api/v1/locations/'.$foreign->id))
            ->assertNotFound();

        $this->assertSame(0, Location::query()->where('name', 'Hidden')->count());
        $this->assertSame(1, Location::withoutGlobalScope(TenantScope::class)->whereKey($foreign->id)->count());
    }

    public function test_an_asset_cannot_point_at_another_workspaces_location(): void
    {
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $foreign = Location::factory()->create(['tenant_id' => $beta['tenant']->id]);
        $this->actingAsWorkspace($alpha);

        $this->postJson($this->tenantUrl('alpha.test', '/api/v1/assets'), [
            'location_id' => $foreign->id,
            'name' => 'Boat',
        ])->assertUnprocessable();

        $this->assertSame(0, Asset::withoutGlobalScope(TenantScope::class)->count());
    }
}
