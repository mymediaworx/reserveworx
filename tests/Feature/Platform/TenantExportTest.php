<?php

namespace Tests\Feature\Platform;

use App\Livewire\Platform\TenantDetail;
use App\Models\Location;
use App\Models\PlatformAdministrator;
use App\Models\TenantDataExport;
use App\Models\User;
use App\Tenancy\TenantScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\Support\CreatesWorkspace;
use Tests\TestCase;

class TenantExportTest extends TestCase
{
    use CreatesWorkspace, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_export_contains_only_that_workspace_and_unlocks_termination(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $alpha = $this->workspace('alpha.test');
        $beta = $this->workspace('beta.test');
        $alpha['user']->forceFill(['email' => 'alpha-owner@example.com'])->save();
        $beta['user']->forceFill(['email' => 'beta-owner@example.com'])->save();
        Location::factory()->create(['tenant_id' => $alpha['tenant']->id, 'name' => 'Alpha Dock']);
        Location::factory()->create(['tenant_id' => $beta['tenant']->id, 'name' => 'Beta Dock']);
        Sanctum::actingAs($admin);

        $this->patchJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id, [
            'status' => 'terminated',
        ])->assertStatus(409);

        $created = $this->postJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id.'/exports')
            ->assertCreated();
        $id = $created->json('data.id');
        $this->assertIsString($id);

        $path = 'tenant-exports/'.$alpha['tenant']->id.'/'.$id.'.json';
        Storage::disk('local')->assertExists($path);
        $json = Storage::disk('local')->get($path);
        $this->assertIsString($json);
        $this->assertStringContainsString('Alpha Dock', $json);
        $this->assertStringNotContainsString('Beta Dock', $json);
        $this->assertStringContainsString('alpha-owner@example.com', $json);
        $this->assertStringNotContainsString('beta-owner@example.com', $json);
        $this->assertStringNotContainsString('"password"', $json);

        $this->get('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id.'/exports/'.$id)
            ->assertOk();

        $this->get('http://localhost/api/v1/platform/tenants/'.$beta['tenant']->id.'/exports/'.$id)
            ->assertNotFound();

        $this->patchJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id, [
            'status' => 'terminated',
        ])->assertOk()->assertJsonPath('data.status', 'terminated');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'tenant.data_exported',
            'tenant_id' => $alpha['tenant']->id,
            'actor_user_id' => $admin->id,
        ]);
    }

    public function test_a_tenant_member_cannot_export_a_workspace(): void
    {
        $alpha = $this->workspace('alpha.test');
        $this->actingAsWorkspace($alpha);

        $this->postJson('http://localhost/api/v1/platform/tenants/'.$alpha['tenant']->id.'/exports')
            ->assertForbidden();

        $this->assertSame(0, TenantDataExport::withoutGlobalScope(TenantScope::class)->count());
    }

    public function test_the_platform_page_exports_and_then_closes(): void
    {
        $admin = User::factory()->create();
        PlatformAdministrator::factory()->create(['user_id' => $admin->id]);
        $alpha = $this->workspace('alpha.test');
        $this->actingAs($admin);

        Livewire::test(TenantDetail::class, ['tenant' => $alpha['tenant']->id])
            ->call('exportData')
            ->assertSet('problem', null)
            ->call('setStatus', 'terminated')
            ->assertSet('problem', null);

        $this->assertSame('terminated', $alpha['tenant']->fresh()?->status->value);

        $exportId = TenantDataExport::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $alpha['tenant']->id)
            ->value('id');
        $this->assertIsString($exportId);

        $this->actingAs($admin)
            ->get('http://app.reserveworx.com/platform/tenants/'.$alpha['tenant']->id.'/exports/'.$exportId)
            ->assertOk();
    }
}
