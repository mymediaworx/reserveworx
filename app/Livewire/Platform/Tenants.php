<?php

namespace App\Livewire\Platform;

use App\Enums\TenantStatus;
use App\Models\Domain;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use App\Services\AuditLogger;
use App\Tenancy\TenantScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every workspace on the platform. Mirrors POST /api/v1/platform/tenants.
 */
#[Layout('layouts.platform')]
#[Title('Workspaces')]
class Tenants extends Component
{
    use WithPagination;

    public string $name = '';

    public string $slug = '';

    public string $search = '';

    public function mount(): void
    {
        $this->guard();
    }

    public function updatedName(string $value): void
    {
        $this->slug = Str::slug($value);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function create(AuditLogger $audit): void
    {
        $actor = $this->guard();
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', 'unique:tenants,slug'],
        ]);

        $tenant = DB::transaction(function () use ($audit, $actor): Tenant {
            $tenant = Tenant::query()->create([
                'name' => $this->name,
                'slug' => $this->slug,
                'status' => TenantStatus::Onboarding,
            ]);
            TenantSetting::withoutGlobalScope(TenantScope::class)->create(['tenant_id' => $tenant->id]);
            $audit->log(
                action: 'tenant.created',
                subject: $tenant,
                properties: ['name' => $tenant->name, 'slug' => $tenant->slug],
                tenantId: $tenant->id,
                actor: $actor,
                request: request(),
                useContextTenant: false,
            );

            return $tenant;
        });

        $this->redirect('/platform/tenants/'.$tenant->id, navigate: true);
    }

    public function render(): View
    {
        $term = trim($this->search);
        $tenants = Tenant::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($inner) => $inner->where('name', 'like', '%'.$term.'%')->orWhere('slug', 'like', '%'.$term.'%')))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.platform.tenants', [
            'tenants' => $tenants,
            'primaryDomains' => Domain::withoutGlobalScope(TenantScope::class)
                ->whereIn('tenant_id', collect($tenants->items())->pluck('id'))
                ->orderByDesc('is_primary')
                ->get()
                ->groupBy('tenant_id')
                ->map(fn ($rows) => $rows->first()),
        ]);
    }

    private function guard(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->isPlatformAdmin(), 403);

        return $user;
    }
}
